<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;

/**
 * Thrown when the Toxiproxy HTTP API answers with a non-success status.
 *
 * Toxiproxy replies with a JSON envelope carrying an "error" message and a
 * "status" code, so both the server-authored message and the status code are
 * preserved here.
 */
class ApiException extends RuntimeException implements ToxiproxyException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $method = '',
        public readonly string $path = '',
        public readonly ?string $body = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(string $method, string $path, int $status, string $body): self
    {
        $message = self::extractMessage($body) ?? sprintf('Toxiproxy API returned HTTP %d', $status);

        return new self(
            sprintf('%s %s failed: %s (HTTP %d)', $method, $path, $message, $status),
            $status,
            $method,
            $path,
            $body,
        );
    }

    /**
     * Toxiproxy always sends a JSON error envelope, but a misconfigured reverse
     * proxy in front of it may not, so fall back to the raw body.
     */
    private static function extractMessage(string $body): ?string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }

        return $trimmed;
    }
}
