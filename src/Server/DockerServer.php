<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Client\Transports;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ServerException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Toxiproxy in a container, using the image Shopify publishes.
 *
 * This is the secondary path. The native binary is simpler, faster to start and
 * has no networking caveats; reach for Docker only if your stack already lives
 * there.
 *
 * Two things behave differently in a container, and neither can be papered over:
 *
 *   Upstreams resolve inside the container. "127.0.0.1:6379" means the
 *   container's own loopback, not your machine's. Use host.docker.internal, or
 *   a service name on a shared Docker network.
 *
 *   Proxy listen ports must be published. A proxy listening on a port that was
 *   not published when the container started is unreachable from the host, so
 *   declare the range up front with publish().
 */
final class DockerServer implements Server
{
    public const DEFAULT_IMAGE = 'ghcr.io/shopify/toxiproxy';

    private bool $owned = false;

    private ?string $startedContainer = null;

    /**
     * @param  array<int, array{int, int}>  $publishedRanges
     * @param  list<string>  $extraArguments
     */
    public function __construct(
        private readonly Configuration $config,
        private readonly ?string $image = null,
        private readonly ?string $containerName = null,
        private readonly array $publishedRanges = [],
        private readonly ?string $network = null,
        private readonly array $extraArguments = [],
        private readonly ?string $binary = null,
    ) {
    }

    public static function create(?Configuration $config = null): self
    {
        return new self($config ?? Configuration::fromEnvironment());
    }

    public function image(): string
    {
        return $this->image ?? self::DEFAULT_IMAGE.':'.$this->config->release()->version;
    }

    public function containerName(): string
    {
        return $this->containerName ?? 'toxiproxy-php-'.$this->config->port;
    }

    public function endpoint(): string
    {
        return $this->config->apiUrl();
    }

    public function client(): ToxiproxyClient
    {
        return new ToxiproxyClient($this->config->apiUrl(), Transports::default());
    }

    public function isRunning(): bool
    {
        return (new ToxiproxyProbe($this->config))->isToxiproxy();
    }

    public function ownsProcess(): bool
    {
        return $this->owned;
    }

    /**
     * Is a usable Docker CLI on this machine, with a daemon that answers?
     */
    public function isAvailable(): bool
    {
        return $this->dockerPath() !== null && $this->daemonResponds();
    }

    /**
     * @param  bool  $detached  ignored: a container always outlives this process
     *                          until it is explicitly stopped
     */
    public function start(bool $detached = true): static
    {
        if ($this->isRunning()) {
            $this->owned = false;

            return $this;
        }

        $docker = $this->requireDocker();
        $name = $this->containerName();

        $this->removeStaleContainer($docker, $name);

        $process = new Process([...$this->runCommand($docker, $name)]);
        $process->setTimeout(180.0);
        $process->run();

        if (! $process->isSuccessful()) {
            throw ServerException::dockerUnavailable(sprintf(
                'docker run failed: %s',
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }

        $this->startedContainer = $name;
        $this->owned = true;

        $this->awaitReadiness();

        return $this;
    }

    public function stop(float $graceSeconds = 5.0): bool
    {
        if (! $this->owned || $this->startedContainer === null) {
            return false;
        }

        $docker = $this->requireDocker();

        $process = new Process([$docker, 'stop', '--timeout', (string) (int) ceil($graceSeconds), $this->startedContainer]);
        $process->setTimeout(60.0);
        $process->run();

        $this->startedContainer = null;
        $this->owned = false;

        return $process->isSuccessful();
    }

    public function logs(): string
    {
        $docker = $this->dockerPath();

        if ($docker === null) {
            return '';
        }

        $process = new Process([$docker, 'logs', '--tail', '200', $this->containerName()]);
        $process->setTimeout(30.0);
        $process->run();

        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * The exact command that will be run, useful for `--dry-run` output and for
     * copying into a docker-compose file.
     *
     * @return list<string>
     */
    public function runCommand(?string $docker = null, ?string $name = null): array
    {
        $command = [
            $docker ?? 'docker',
            'run',
            '--detach',
            '--rm',
            '--name', $name ?? $this->containerName(),
        ];

        if ($this->network !== null) {
            $command[] = '--network';
            $command[] = $this->network;
        }

        // With host networking the container shares the host's stack, so
        // publishing would be rejected as meaningless.
        if ($this->network !== 'host') {
            $command[] = '--publish';
            $command[] = sprintf('%s:%d:8474', $this->config->host, $this->config->port);

            foreach ($this->publishedRanges as [$from, $to]) {
                $command[] = '--publish';
                $command[] = sprintf('%s:%d-%d:%d-%d', $this->config->proxyHost, $from, $to, $from, $to);
            }
        }

        $command[] = '--env';
        $command[] = 'LOG_LEVEL='.$this->config->logLevel;

        foreach ($this->extraArguments as $argument) {
            $command[] = $argument;
        }

        $command[] = $this->image();

        // The container listens on all interfaces so the published port maps
        // through; the host side is still bound to config->host.
        $command[] = '-host=0.0.0.0';
        $command[] = '-port=8474';

        return $command;
    }

    private function awaitReadiness(): void
    {
        $probe = new ToxiproxyProbe($this->config);
        $deadline = microtime(true) + max($this->config->startTimeout, 30.0);

        while (microtime(true) < $deadline) {
            if ($probe->isToxiproxy()) {
                return;
            }

            usleep(100_000);
        }

        throw ServerException::didNotBecomeReady(
            $this->config->apiUrl(),
            max($this->config->startTimeout, 30.0),
            $this->logs(),
        );
    }

    private function removeStaleContainer(string $docker, string $name): void
    {
        // --rm cleans up on a graceful stop, but a killed daemon or a crashed
        // container leaves the name taken and docker run would refuse it.
        $process = new Process([$docker, 'rm', '--force', $name]);
        $process->setTimeout(30.0);
        $process->run();
    }

    private function requireDocker(): string
    {
        $docker = $this->dockerPath();

        if ($docker === null) {
            throw ServerException::dockerUnavailable('no docker executable on PATH');
        }

        if (! $this->daemonResponds()) {
            throw ServerException::dockerUnavailable('the Docker daemon is not responding');
        }

        return $docker;
    }

    private function dockerPath(): ?string
    {
        return $this->binary ?? (new ExecutableFinder())->find('docker');
    }

    private function daemonResponds(): bool
    {
        $docker = $this->dockerPath();

        if ($docker === null) {
            return false;
        }

        $process = new Process([$docker, 'info', '--format', '{{.ServerVersion}}']);
        $process->setTimeout(20.0);
        $process->run();

        return $process->isSuccessful();
    }
}
