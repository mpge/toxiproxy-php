<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Server\DockerServer;
use Mpge\Toxiproxy\Server\Server;
use Mpge\Toxiproxy\Server\ToxiproxyServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared option handling for every command.
 *
 * Options beat environment variables beat defaults, which is the order people
 * expect and the only one that lets a single CLI invocation deviate without
 * exporting anything.
 */
abstract class ToxiproxyCommand extends Command
{
    /**
     * The binary version is exposed as --release, not --version: Symfony
     * Console reserves --version for the application itself.
     */
    protected function addConnectionOptions(bool $includeServerOptions = true): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host the Toxiproxy API listens on')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port the Toxiproxy API listens on')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Full API base URL, overriding host and port');

        if (! $includeServerOptions) {
            return;
        }

        $this
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Toxiproxy release to use, or "latest"')
            ->addOption('binary', 'b', InputOption::VALUE_REQUIRED, 'Path to a toxiproxy-server binary to use as-is')
            ->addOption('home', null, InputOption::VALUE_REQUIRED, 'Directory to cache downloaded binaries in')
            ->addOption('log-level', null, InputOption::VALUE_REQUIRED, 'Server log level: trace, debug, info, warn, error')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for the server to become ready')
            ->addOption('docker', null, InputOption::VALUE_NONE, 'Run Toxiproxy as a container instead of a native binary');
    }

    protected function configuration(InputInterface $input): Configuration
    {
        $config = Configuration::fromEnvironment();

        $url = $this->stringOption($input, 'url');

        if ($url !== null) {
            // Applied as host and port rather than by rebuilding from a faked
            // environment, so --url does not quietly discard TOXIPROXY_VERSION
            // and friends.
            $parts = parse_url(str_contains($url, '://') ? $url : 'http://'.$url);

            if (is_array($parts) && isset($parts['host'])) {
                $config = $config
                    ->withHost($parts['host'])
                    ->withPort($parts['port'] ?? ToxiproxyClient::DEFAULT_PORT);
            } else {
                throw new InvalidArgumentException(sprintf('--url expected a URL, got "%s".', $url));
            }
        }

        $host = $this->stringOption($input, 'host');

        if ($host !== null) {
            $config = $config->withHost($host);
        }

        $port = $this->stringOption($input, 'port');

        if ($port !== null) {
            $config = $config->withPort((int) $port);
        }

        $release = $this->stringOption($input, 'release');

        if ($release !== null) {
            $config = $config->withVersion($release);
        }

        $binary = $this->stringOption($input, 'binary');

        if ($binary !== null) {
            $config = $config->withBinary($binary);
        }

        $home = $this->stringOption($input, 'home');

        if ($home !== null) {
            $config = $config->withHome($home);
        }

        $logLevel = $this->stringOption($input, 'log-level');

        if ($logLevel !== null) {
            $config = $config->withLogLevel($logLevel);
        }

        $timeout = $this->stringOption($input, 'timeout');

        if ($timeout !== null && is_numeric($timeout)) {
            $config = $config->withStartTimeout((float) $timeout);
        }

        return $config;
    }

    protected function server(InputInterface $input, ?Configuration $config = null): Server
    {
        $config ??= $this->configuration($input);

        return $input->hasOption('docker') && $input->getOption('docker') === true
            ? DockerServer::create($config)
            : ToxiproxyServer::create($config);
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        /** @var mixed $value */
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
