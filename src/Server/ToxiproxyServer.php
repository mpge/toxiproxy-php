<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Client\Transports;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ServerException;
use Symfony\Component\Process\Process;

/**
 * The lifecycle of one Toxiproxy server.
 *
 *     $server = ToxiproxyServer::create();
 *     $server->ensureInstalled();
 *     $server->start();
 *     $server->stop();
 *
 * The rule this class exists to enforce: **only stop what you started.**
 *
 * start() reuses a server already answering on the configured endpoint instead
 * of failing or spawning a duplicate, and remembers that it did not start it.
 * stop() on such a server is a no-op that returns false, not a kill. Somebody
 * else's docker-compose Toxiproxy, or one a colleague left running, survives
 * your test suite untouched.
 */
final class ToxiproxyServer implements Server
{
    private ?Process $process = null;

    private ?ServerRecord $record = null;

    private bool $owned = false;

    private bool $shutdownHookRegistered = false;

    public function __construct(
        private readonly Configuration $config,
        private readonly BinaryManager $binaries,
        private readonly ProcessManager $processes,
    ) {
    }

    public static function create(?Configuration $config = null): self
    {
        $config ??= Configuration::fromEnvironment();
        $registry = ServerRegistry::inHome($config->homeDirectory());

        return new self(
            $config,
            new BinaryManager($config, Platform::current()),
            new ProcessManager($config, $registry),
        );
    }

    public function config(): Configuration
    {
        return $this->config;
    }

    public function binaries(): BinaryManager
    {
        return $this->binaries;
    }

    public function endpoint(): string
    {
        return $this->config->apiUrl();
    }

    public function client(): ToxiproxyClient
    {
        return new ToxiproxyClient($this->config->apiUrl(), Transports::default());
    }

    public function probe(): ToxiproxyProbe
    {
        return new ToxiproxyProbe($this->config);
    }

    /**
     * Make sure a server binary is available, downloading it if allowed.
     */
    public function ensureInstalled(bool $force = false): string
    {
        return $force ? $this->binaries->install(force: true) : $this->binaries->resolve();
    }

    public function isRunning(): bool
    {
        return $this->probe()->isToxiproxy();
    }

    /**
     * True when this object started the server it is talking to.
     */
    public function ownsProcess(): bool
    {
        return $this->owned;
    }

    public function pid(): ?int
    {
        return $this->record !== null ? $this->record->pid : $this->process?->getPid();
    }

    /**
     * Start the server, or adopt one that is already there.
     *
     * @param  bool  $detached  leave the server running after this PHP process exits
     */
    public function start(bool $detached = false): static
    {
        if ($this->isRunning()) {
            // Somebody else's server. Use it, but never assume the right to
            // stop it later.
            $this->owned = false;

            return $this;
        }

        $binary = $this->ensureInstalled();

        if ($detached) {
            $this->record = $this->processes->startDetached($binary);
            $this->process = null;
        } else {
            $this->process = $this->processes->startAttached($binary);
            $this->record = null;
            $this->registerShutdownHook();
        }

        $this->owned = true;

        return $this;
    }

    /**
     * Stop the server, but only if this object started it.
     *
     * @return bool  true when a server was actually stopped
     */
    public function stop(float $graceSeconds = 5.0): bool
    {
        if (! $this->owned) {
            return false;
        }

        $stopped = false;

        if ($this->process !== null) {
            $this->process->stop($graceSeconds);
            $stopped = true;
            $this->processes->registry()->forget($this->config->host, $this->config->port);
        }

        if ($this->record !== null) {
            $stopped = $this->processes->stopRecorded($this->record, $graceSeconds);
        }

        $this->process = null;
        $this->record = null;
        $this->owned = false;

        return $stopped;
    }

    /**
     * Stop a server started by an earlier process, using the registry.
     *
     * This is what `toxiproxy-php stop` calls. It refuses endpoints with no
     * record, which is precisely how an externally managed server is protected.
     *
     * @throws ServerException  when a server is running but was not started by us
     */
    public function stopRecorded(float $graceSeconds = 5.0): bool
    {
        if ($this->owned) {
            return $this->stop($graceSeconds);
        }

        $record = $this->processes->registry()->find($this->config->host, $this->config->port);

        if ($record === null) {
            if ($this->isRunning()) {
                throw ServerException::notOwned($this->config->apiUrl());
            }

            return false;
        }

        return $this->processes->stopRecorded($record, $graceSeconds);
    }

    public function restart(bool $detached = false): static
    {
        $this->stop();

        return $this->start($detached);
    }

    /**
     * Whatever the server has written to stdout and stderr so far.
     *
     * Attached servers buffer in memory; detached ones write to a log file.
     */
    public function logs(): string
    {
        if ($this->process !== null) {
            return $this->process->getOutput().$this->process->getErrorOutput();
        }

        return $this->processes->readLogs();
    }

    public function logFile(): string
    {
        return $this->processes->defaultLogFile();
    }

    public function registry(): ServerRegistry
    {
        return $this->processes->registry();
    }

    /**
     * Belt and braces against orphans.
     *
     * Symfony's Process already stops the child when this object is collected,
     * but a fatal error can bypass that; the shutdown function cannot.
     */
    private function registerShutdownHook(): void
    {
        if ($this->shutdownHookRegistered) {
            return;
        }

        $this->shutdownHookRegistered = true;

        register_shutdown_function(function (): void {
            if ($this->owned && $this->process !== null) {
                $this->stop(2.0);
            }
        });
    }
}
