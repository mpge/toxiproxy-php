<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use JsonException;
use JsonSerializable;

/**
 * What we know about a Toxiproxy server this package started.
 *
 * Persisted so a later PHP process, in particular the CLI, can tell the
 * difference between "a server I am responsible for" and "a server somebody
 * else runs", and only ever stop the former.
 */
final readonly class ServerRecord implements JsonSerializable
{
    public function __construct(
        public string $host,
        public int $port,
        public int $pid,
        public string $binary,
        public int $startedAt,
        public bool $detached,
        public int $startedByPid,
    ) {
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        foreach (['host', 'port', 'pid', 'binary', 'startedAt', 'startedByPid'] as $field) {
            if (! isset($payload[$field])) {
                return null;
            }
        }

        if (! is_string($payload['host']) || ! is_string($payload['binary'])) {
            return null;
        }

        if (! is_int($payload['port']) || ! is_int($payload['pid'])) {
            return null;
        }

        return new self(
            $payload['host'],
            $payload['port'],
            $payload['pid'],
            $payload['binary'],
            is_int($payload['startedAt']) ? $payload['startedAt'] : 0,
            (bool) ($payload['detached'] ?? false),
            is_int($payload['startedByPid']) ? $payload['startedByPid'] : 0,
        );
    }

    public static function decode(string $json): ?self
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? self::fromArray($payload) : null;
    }

    public function endpoint(): string
    {
        return sprintf('%s:%d', $this->host, $this->port);
    }

    public function uptimeSeconds(int $now): int
    {
        return max(0, $now - $this->startedAt);
    }

    /**
     * @return array<string, scalar>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'pid' => $this->pid,
            'binary' => $this->binary,
            'startedAt' => $this->startedAt,
            'detached' => $this->detached,
            'startedByPid' => $this->startedByPid,
        ];
    }
}
