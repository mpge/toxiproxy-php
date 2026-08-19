<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;

/**
 * Thrown for failures in the lifecycle of the Toxiproxy server process.
 */
class ServerException extends RuntimeException implements ToxiproxyException
{
    public static function portInUse(string $host, int $port, string $occupant): self
    {
        return new self(sprintf(
            'Cannot start Toxiproxy: %s:%d is already in use by %s. '
            .'Either stop that process or choose another port with TOXIPROXY_PORT.',
            $host,
            $port,
            $occupant,
        ));
    }

    public static function didNotBecomeReady(string $endpoint, float $seconds, string $log): self
    {
        $message = sprintf(
            'Toxiproxy did not start listening on %s within %.1f seconds.',
            $endpoint,
            $seconds,
        );

        return self::withLog($message, $log);
    }

    public static function exitedEarly(int $exitCode, string $log): self
    {
        return self::withLog(
            sprintf('The Toxiproxy server process exited immediately with code %d.', $exitCode),
            $log,
        );
    }

    public static function notOwned(string $endpoint): self
    {
        return new self(sprintf(
            'Refusing to stop the Toxiproxy server at %s because this process did not start it. '
            .'Externally managed servers are left alone on purpose.',
            $endpoint,
        ));
    }

    public static function dockerUnavailable(string $reason): self
    {
        return new self(sprintf(
            'Docker is not usable for running Toxiproxy: %s. '
            .'The native binary is the primary path; drop the Docker option to use it.',
            $reason,
        ));
    }

    private static function withLog(string $message, string $log): self
    {
        if (trim($log) !== '') {
            $message .= sprintf("\nServer output:\n%s", trim($log));
        }

        return new self($message);
    }
}
