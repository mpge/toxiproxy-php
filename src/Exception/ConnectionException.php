<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when the Toxiproxy API cannot be reached at all: connection refused,
 * DNS failure, TLS problem or timeout.
 */
class ConnectionException extends RuntimeException implements ToxiproxyException
{
    public static function forEndpoint(string $endpoint, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Could not reach the Toxiproxy API at %s: %s. Is the server running? Try "vendor/bin/toxiproxy-php doctor".',
                $endpoint,
                $reason,
            ),
            0,
            $previous,
        );
    }
}
