<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use Mpge\Toxiproxy\Exception\ServerException;

/**
 * Finds a free local TCP port.
 *
 * This is the fallback path only. When a proxy is created enabled, Toxiproxy is
 * asked to bind port 0 and reports back the port the kernel handed it, which is
 * race-free. Bind-then-release, as done here, has an unavoidable window between
 * releasing the port and something else claiming it, so it is used only where
 * the server cannot do the work for us: disabled proxies, whose listener never
 * starts, and the API port of a server we are about to spawn.
 */
final class PortAllocator
{
    public static function free(string $host = Address::DEFAULT_HOST): int
    {
        $socket = @stream_socket_server(
            sprintf('tcp://%s:0', self::formatHost($host)),
            $errno,
            $errstr,
        );

        if ($socket === false) {
            throw new ServerException(sprintf(
                'Could not allocate a local port on %s: %s (%d).',
                $host,
                $errstr === '' ? 'unknown error' : $errstr,
                $errno,
            ));
        }

        try {
            $name = stream_socket_get_name($socket, false);
        } finally {
            fclose($socket);
        }

        if ($name === false) {
            throw new ServerException('Could not read back the port the operating system allocated.');
        }

        return Address::parse($name, $host)->port;
    }

    public static function isAvailable(string $host, int $port): bool
    {
        $socket = @stream_socket_server(
            sprintf('tcp://%s:%d', self::formatHost($host), $port),
            $errno,
            $errstr,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * True when something is already listening on the port.
     */
    public static function isInUse(string $host, int $port, float $timeout = 0.5): bool
    {
        $connection = @stream_socket_client(
            sprintf('tcp://%s:%d', self::formatHost($host), $port),
            $errno,
            $errstr,
            $timeout,
        );

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private static function formatHost(string $host): string
    {
        return str_contains($host, ':') && ! str_starts_with($host, '[')
            ? '['.$host.']'
            : $host;
    }
}
