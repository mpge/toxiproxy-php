<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy;

use Mpge\Toxiproxy\Client\Transport;
use Mpge\Toxiproxy\Client\Transports;
use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Server\DockerServer;
use Mpge\Toxiproxy\Server\Server;
use Mpge\Toxiproxy\Server\ToxiproxyServer;

/**
 * A Toxiproxy that has been described but not started yet.
 *
 *     Toxiproxy::make()
 *         ->port(9474)
 *         ->version('2.11.0')
 *         ->debug()
 *         ->start();
 *
 * Every setter returns a new instance, so a half-built configuration cannot
 * leak between tests.
 */
final readonly class PendingToxiproxy
{
    /**
     * @param  array<int, array{int, int}>  $publishedRanges
     * @param  list<string>  $dockerArguments
     */
    public function __construct(
        private Configuration $config,
        private bool $useDocker = false,
        private bool $detached = false,
        private ?Transport $transport = null,
        private ?string $image = null,
        private ?string $containerName = null,
        private array $publishedRanges = [],
        private ?string $network = null,
        private array $dockerArguments = [],
    ) {
    }

    public function configuration(): Configuration
    {
        return $this->config;
    }

    // ------------------------------------------------------------ server side

    public function host(string $host): self
    {
        return $this->with(config: $this->config->withHost($host));
    }

    public function port(int $port): self
    {
        return $this->with(config: $this->config->withPort($port));
    }

    public function version(string $version): self
    {
        return $this->with(config: $this->config->withVersion($version));
    }

    /**
     * Use this exact binary instead of anything cached or downloaded.
     */
    public function binary(?string $path): self
    {
        return $this->with(config: $this->config->withBinary($path));
    }

    public function home(?string $directory): self
    {
        return $this->with(config: $this->config->withHome($directory));
    }

    public function autoInstall(bool $autoInstall = true): self
    {
        return $this->with(config: $this->config->withAutoInstall($autoInstall));
    }

    public function logLevel(string $level): self
    {
        return $this->with(config: $this->config->withLogLevel($level));
    }

    public function startTimeout(float $seconds): self
    {
        return $this->with(config: $this->config->withStartTimeout($seconds));
    }

    /**
     * The interface new proxies listen on. Defaults to 127.0.0.1, which keeps
     * your fault injection off the network.
     */
    public function proxyHost(string $host): self
    {
        return $this->with(config: $this->config->withProxyHost($host));
    }

    public function verifyChecksums(bool $verify = true): self
    {
        return $this->with(config: $this->config->withVerifyChecksums($verify));
    }

    public function debug(bool $debug = true): self
    {
        return $this->with(config: $this->config->withDebug($debug));
    }

    /**
     * Leave the server running after this PHP process exits.
     */
    public function detached(bool $detached = true): self
    {
        return $this->with(detached: $detached);
    }

    public function transport(Transport $transport): self
    {
        return $this->with(transport: $transport);
    }

    // ------------------------------------------------------------------ docker

    public function docker(bool $useDocker = true): self
    {
        return $this->with(useDocker: $useDocker);
    }

    public function image(string $image): self
    {
        return $this->with(image: $image, useDocker: true);
    }

    public function containerName(string $name): self
    {
        return $this->with(containerName: $name, useDocker: true);
    }

    /**
     * Publish a range of container ports so proxies listening on them are
     * reachable from the host. Docker-only, and required there.
     */
    public function publish(int $from, int $to): self
    {
        return $this->with(
            publishedRanges: [...$this->publishedRanges, [$from, $to]],
            useDocker: true,
        );
    }

    /**
     * Join an existing Docker network. Passing "host" on Linux removes the
     * port-publishing problem entirely.
     */
    public function network(string $network): self
    {
        return $this->with(network: $network, useDocker: true);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function dockerArguments(array $arguments): self
    {
        return $this->with(dockerArguments: $arguments, useDocker: true);
    }

    // --------------------------------------------------------------- terminal

    public function server(): Server
    {
        return $this->useDocker
            ? new DockerServer(
                $this->config,
                $this->image,
                $this->containerName,
                $this->publishedRanges,
                $this->network,
                $this->dockerArguments,
            )
            : ToxiproxyServer::create($this->config);
    }

    /**
     * Install if needed, start if not already running, and return a client.
     */
    public function start(): Toxiproxy
    {
        $server = $this->server();
        $server->start($this->detached);

        return new Toxiproxy(
            new ToxiproxyClient($server->endpoint(), $this->transport ?? Transports::default()),
            $server,
            $this->config,
        );
    }

    /**
     * Connect without starting anything.
     */
    public function connect(): Toxiproxy
    {
        return new Toxiproxy(
            new ToxiproxyClient($this->config->apiUrl(), $this->transport ?? Transports::default()),
            null,
            $this->config,
        );
    }

    /**
     * Download the binary without starting a server.
     */
    public function install(bool $force = false): string
    {
        return ToxiproxyServer::create($this->config)->ensureInstalled($force);
    }

    /**
     * @param  array<int, array{int, int}>|null  $publishedRanges
     * @param  list<string>|null  $dockerArguments
     */
    private function with(
        ?Configuration $config = null,
        ?bool $useDocker = null,
        ?bool $detached = null,
        ?Transport $transport = null,
        ?string $image = null,
        ?string $containerName = null,
        ?array $publishedRanges = null,
        ?string $network = null,
        ?array $dockerArguments = null,
    ): self {
        return new self(
            $config ?? $this->config,
            $useDocker ?? $this->useDocker,
            $detached ?? $this->detached,
            $transport ?? $this->transport,
            $image ?? $this->image,
            $containerName ?? $this->containerName,
            $publishedRanges ?? $this->publishedRanges,
            $network ?? $this->network,
            $dockerArguments ?? $this->dockerArguments,
        );
    }
}
