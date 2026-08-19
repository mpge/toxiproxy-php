<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use CurlHandle;
use Mpge\Toxiproxy\Exception\ConnectionException;

/**
 * The default transport: a thin, dependency-free wrapper around ext-curl.
 *
 * Toxiproxy is a localhost control plane, so this deliberately does not try to
 * be a general-purpose HTTP client. It does exactly what the API needs and
 * nothing else.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 2.0,
    ) {
    }

    public static function isSupported(): bool
    {
        return extension_loaded('curl') && function_exists('curl_init');
    }

    public function send(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $handle = curl_init();

        if (! $handle instanceof CurlHandle) {
            throw ConnectionException::forEndpoint($url, 'curl_init() failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeout * 1000),
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        $errno = curl_errno($handle);

        if ($errno !== 0 || $result === false) {
            throw ConnectionException::forEndpoint($url, curl_error($handle) ?: 'curl error '.$errno);
        }

        /** @var int $status */
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // No curl_close(): it is a deprecated no-op since PHP 8.0, and the
        // handle is released when this CurlHandle goes out of scope.
        return new Response($status, is_string($result) ? $result : '');
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name.': '.$value;
        }

        // Suppress curl's automatic "Expect: 100-continue" on larger bodies;
        // Go's net/http answers it, but the extra round trip buys nothing here.
        $formatted[] = 'Expect:';

        return $formatted;
    }
}
