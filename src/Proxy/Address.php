<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use Mpge\Toxiproxy\Exception\InvalidArgumentException;

/**
 * Parsing and formatting for the host:port strings Toxiproxy trades in.
 *
 * Handles bare ports ("6379"), host-less forms (":6379") and IPv6 literals
 * ("[::1]:6379"), all of which Go's net.SplitHostPort accepts or produces.
 */
final readonly class Address
{
    public const DEFAULT_HOST = '127.0.0.1';

    public function __construct(
        public string $host,
        public int $port,
    ) {
        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('Port %d is outside the valid range 0-65535.', $port));
        }
    }

    /**
     * @param  string  $default  host to assume when the input carries none
     */
    public static function parse(string $value, string $default = self::DEFAULT_HOST): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Cannot parse an empty address.');
        }

        // A bare port: "6379".
        if (ctype_digit($trimmed)) {
            return new self($default, (int) $trimmed);
        }

        // IPv6 literal: "[::1]:6379".
        if (str_starts_with($trimmed, '[')) {
            $close = strrpos($trimmed, ']');

            if ($close === false || ! isset($trimmed[$close + 1]) || $trimmed[$close + 1] !== ':') {
                throw new InvalidArgumentException(sprintf('Cannot parse "%s" as an IPv6 host:port address.', $value));
            }

            return new self(
                substr($trimmed, 1, $close - 1),
                self::parsePort(substr($trimmed, $close + 2), $value),
            );
        }

        $separator = strrpos($trimmed, ':');

        if ($separator === false) {
            throw new InvalidArgumentException(sprintf(
                'Cannot parse "%s" as an address. Expected "host:port", ":port" or a bare port.',
                $value,
            ));
        }

        $host = substr($trimmed, 0, $separator);

        return new self(
            $host === '' ? $default : $host,
            self::parsePort(substr($trimmed, $separator + 1), $value),
        );
    }

    /**
     * Port 0 asks the operating system, via Toxiproxy, for any free port.
     */
    public function isEphemeral(): bool
    {
        return $this->port === 0;
    }

    public function withPort(int $port): self
    {
        return new self($this->host, $port);
    }

    public function withHost(string $host): self
    {
        return new self($host, $this->port);
    }

    public function toString(): string
    {
        return str_contains($this->host, ':')
            ? sprintf('[%s]:%d', $this->host, $this->port)
            : sprintf('%s:%d', $this->host, $this->port);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function parsePort(string $port, string $original): int
    {
        if (! ctype_digit($port)) {
            throw new InvalidArgumentException(sprintf('Cannot parse "%s": "%s" is not a port number.', $original, $port));
        }

        return (int) $port;
    }
}
