<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;

/**
 * Thrown when the current OS/architecture pair has no official Toxiproxy
 * release binary. Users on such platforms can still point TOXIPROXY_BINARY at
 * a server they built themselves, or connect to an externally managed one.
 */
class UnsupportedPlatformException extends RuntimeException implements ToxiproxyException
{
    public static function for(string $os, string $architecture): self
    {
        return new self(sprintf(
            'Shopify does not publish a Toxiproxy server binary for %s/%s. '
            .'Set TOXIPROXY_BINARY to a server you built yourself, or point the client at an '
            .'externally managed Toxiproxy with TOXIPROXY_HOST and TOXIPROXY_PORT.',
            $os,
            $architecture,
        ));
    }
}
