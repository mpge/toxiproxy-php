<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use Mpge\Toxiproxy\Exception\ConnectionException;

/**
 * Fallback transport built on PHP stream wrappers, for builds without ext-curl.
 *
 * allow_url_fopen must be enabled. If neither this nor ext-curl is usable,
 * supply your own Transport or a PSR-18 client via Psr18Transport.
 */
final class StreamTransport implements Transport
{
    public function __construct(
        private readonly float $timeout = 5.0,
    ) {
    }

    public static function isSupported(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
    }

    public function send(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
        ]);

        $previous = null;
        set_error_handler(static function (int $severity, string $message) use (&$previous): bool {
            $previous = $message;

            return true;
        });

        try {
            $result = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw ConnectionException::forEndpoint($url, is_string($previous) ? $previous : 'stream request failed');
        }

        return new Response($this->statusFromHeaders($http_response_header ?? []), $result);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        // With follow_location off there is exactly one status line, but keep
        // the last one anyway so an intermediary cannot confuse us.
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
