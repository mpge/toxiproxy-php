<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Exception\BinaryException;

/**
 * A record on disk of the servers this package started.
 *
 * This is what makes "never kill somebody else's Toxiproxy" enforceable across
 * process boundaries: `toxiproxy-php stop` will only touch an endpoint it finds
 * a record for, and a server started by hand or by docker-compose has none.
 *
 * One file per host:port, so several servers on different ports coexist.
 */
final readonly class ServerRegistry
{
    public function __construct(private string $directory)
    {
    }

    public static function inHome(string $home): self
    {
        return new self(rtrim($home, '/\\').DIRECTORY_SEPARATOR.'run');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function record(ServerRecord $record): void
    {
        $this->ensureDirectory();

        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (@file_put_contents($this->path($record->host, $record->port), $encoded) === false) {
            throw BinaryException::unwritableDirectory($this->directory);
        }
    }

    public function find(string $host, int $port): ?ServerRecord
    {
        $path = $this->path($host, $port);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : ServerRecord::decode($contents);
    }

    public function forget(string $host, int $port): void
    {
        $path = $this->path($host, $port);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<ServerRecord>
     */
    public function all(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $records = [];

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $contents = @file_get_contents($file);
            $record = $contents === false ? null : ServerRecord::decode($contents);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Drop records whose process is gone, so a crashed server does not look
     * like a live one forever.
     *
     * @return list<ServerRecord>  the records that were removed
     */
    public function prune(ProcessControl $processes): array
    {
        $removed = [];

        foreach ($this->all() as $record) {
            if (! $processes->isAlive($record->pid)) {
                $this->forget($record->host, $record->port);
                $removed[] = $record;
            }
        }

        return $removed;
    }

    private function path(string $host, int $port): string
    {
        $key = preg_replace('/[^A-Za-z0-9._-]/', '_', $host.'_'.$port) ?? 'server';

        return $this->directory.DIRECTORY_SEPARATOR.$key.'.json';
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o755, true) && ! is_dir($this->directory)) {
            throw BinaryException::unwritableDirectory($this->directory);
        }
    }
}
