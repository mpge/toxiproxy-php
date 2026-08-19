<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use Mpge\Toxiproxy\Exception\ConnectionException;

/**
 * Picks a usable transport without making the caller think about it.
 */
final class Transports
{
    public static function default(float $timeout = 5.0): Transport
    {
        if (CurlTransport::isSupported()) {
            return new CurlTransport($timeout);
        }

        if (StreamTransport::isSupported()) {
            return new StreamTransport($timeout);
        }

        throw new ConnectionException(
            'No usable HTTP transport. Install ext-curl, enable allow_url_fopen, '
            .'or pass your own Transport (see Psr18Transport).'
        );
    }
}
