<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Console\Application;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Proxy\PortAllocator;
use Mpge\Toxiproxy\Server\BinaryManager;
use Mpge\Toxiproxy\Server\DockerServer;
use Mpge\Toxiproxy\Server\Platform;
use Mpge\Toxiproxy\Server\ProcessControl;
use Mpge\Toxiproxy\Server\Release;
use Mpge\Toxiproxy\Server\ServerRegistry;
use Mpge\Toxiproxy\Server\ToxiproxyProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'doctor',
    description: 'Diagnose the environment: platform, binary, server, ports and connectivity',
)]
final class DoctorCommand extends ToxiproxyCommand
{
    private const OK = 'ok';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    protected function configure(): void
    {
        $this->addConnectionOptions();

        $this->setHelp(<<<'HELP'
        Checks everything that has to be true for this package to work, and says what
        to do about anything that is not.

        Exits non-zero only for problems that would actually stop you working; a
        server that simply is not running yet is reported, not failed.
        HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);

        $io->title('toxiproxy-php doctor');

        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $rows */
        $rows = [
            ...$this->runtimeChecks(),
            ...$this->platformChecks($config),
            ...$this->binaryChecks($config),
            ...$this->serverChecks($config),
            ...$this->dockerChecks($config),
        ];

        foreach ($rows as [$status, $label, $value, $note]) {
            $io->writeln(sprintf(
                '%s  %-22s %s%s',
                $this->badge($status),
                $label,
                $value,
                $note === '' ? '' : "\n                        <comment>".$note.'</comment>',
            ));
        }

        $failures = array_filter($rows, static fn (array $row): bool => $row[0] === self::FAIL);

        $io->writeln('');

        if ($failures !== []) {
            $io->error(sprintf('%d check(s) need attention.', count($failures)));

            return self::FAILURE;
        }

        $io->success('Everything checks out.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function runtimeChecks(): array
    {
        $rows = [];

        $rows[] = [
            version_compare(PHP_VERSION, '8.2.0', '>=') ? self::OK : self::FAIL,
            'PHP',
            PHP_VERSION,
            version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : 'This package needs PHP 8.2 or newer.',
        ];

        $rows[] = [self::OK, 'Package', Application::VERSION, ''];

        $hasCurl = extension_loaded('curl');
        $hasStreams = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);

        $rows[] = [
            match (true) {
                $hasCurl => self::OK,
                $hasStreams => self::WARN,
                default => self::FAIL,
            },
            'HTTP transport',
            match (true) {
                $hasCurl => 'ext-curl',
                $hasStreams => 'stream wrappers',
                default => 'none',
            },
            match (true) {
                $hasCurl => '',
                $hasStreams => 'ext-curl is not loaded; falling back to stream wrappers, which cannot report download progress.',
                default => 'Install ext-curl or enable allow_url_fopen, or supply your own Transport.',
            },
        ];

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function platformChecks(Configuration $config): array
    {
        $platform = Platform::current();
        $supported = $platform->isSupported();

        return [
            [
                $supported ? self::OK : self::FAIL,
                'Platform',
                $platform->toString(),
                $supported
                    ? ''
                    : 'Shopify publishes no server binary for this platform. Supported: '
                        .implode(', ', Platform::supportedTargets()),
            ],
            [
                self::OK,
                'Cache directory',
                $config->homeDirectory($platform),
                is_writable(dirname($config->homeDirectory($platform)))
                    ? ''
                    : 'This directory is not writable. Set TOXIPROXY_HOME somewhere else.',
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function binaryChecks(Configuration $config): array
    {
        $binaries = BinaryManager::create($config);
        $path = $binaries->locate();

        if ($path === null) {
            return [[
                $config->autoInstall ? self::WARN : self::FAIL,
                'Server binary',
                'not installed',
                $config->autoInstall
                    ? 'It will be downloaded on first use. Install it now with: toxiproxy-php install'
                    : 'Auto-install is off. Run: toxiproxy-php install',
            ]];
        }

        $version = $binaries->installedVersion($path);
        $wanted = $config->wantsLatest() ? null : (new Release($config->version))->version;

        $rows = [[
            $version === null ? self::WARN : self::OK,
            'Server binary',
            sprintf('%s  %s', $version ?? 'unreadable version', $path),
            $version === null ? 'The binary did not answer -version. It may be corrupt; re-run install --force.' : '',
        ]];

        if ($binaries->isUsingSystemBinary()) {
            $rows[] = [
                self::WARN,
                'Binary source',
                'found on PATH',
                'This binary was installed outside this package, so its version is not controlled by '
                .'TOXIPROXY_VERSION. Set TOXIPROXY_BINARY to pin it, or remove it from PATH.',
            ];
        }

        if ($version !== null && $wanted !== null && $version !== $wanted) {
            $rows[] = [
                self::WARN,
                'Binary version',
                sprintf('%s, wanted %s', $version, $wanted),
                'Run: toxiproxy-php install --force',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function serverChecks(Configuration $config): array
    {
        $probe = new ToxiproxyProbe($config);
        $version = $probe->version();
        $portOpen = $probe->isPortOpen();

        $rows = [];

        if ($version !== null) {
            $rows[] = [self::OK, 'API', sprintf('%s  Toxiproxy %s', $config->apiUrl(), $version), ''];
        } elseif ($portOpen) {
            $rows[] = [
                self::FAIL,
                'API',
                sprintf('%s  occupied', $config->apiUrl()),
                'Something is listening on this port but it does not speak the Toxiproxy API. '
                .'Free the port or choose another with TOXIPROXY_PORT.',
            ];
        } else {
            $rows[] = [
                self::WARN,
                'API',
                sprintf('%s  not running', $config->apiUrl()),
                'Start one with: toxiproxy-php start',
            ];
        }

        $registry = ServerRegistry::inHome($config->homeDirectory());
        $record = $registry->find($config->host, $config->port);

        if ($record !== null) {
            $alive = (new ProcessControl())->isAlive($record->pid);

            $rows[] = [
                $alive ? self::OK : self::WARN,
                'Ownership',
                sprintf('started by this package, pid %d', $record->pid),
                $alive ? '' : 'That process is gone. The stale record will be cleared on the next start.',
            ];
        } elseif ($version !== null) {
            $rows[] = [
                self::OK,
                'Ownership',
                'external',
                'This server was started by something else, so stop will refuse to kill it.',
            ];
        }

        // Proxies bind on demand, so all that can be checked here is that we can
        // bind at all on the configured interface.
        $canBind = PortAllocator::isAvailable($config->proxyHost, 0);

        $rows[] = [
            $canBind ? self::OK : self::FAIL,
            'Proxy interface',
            $config->proxyHost,
            $canBind ? '' : sprintf('Cannot bind a local port on %s. Set TOXIPROXY_PROXY_HOST.', $config->proxyHost),
        ];

        if ($version !== null) {
            try {
                $proxies = (new ToxiproxyClient($config->apiUrl()))->proxies();
                $rows[] = [self::OK, 'Proxies', sprintf('%d defined', count($proxies)), ''];
            } catch (ToxiproxyException $e) {
                $rows[] = [self::FAIL, 'Proxies', 'unreadable', $e->getMessage()];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function dockerChecks(Configuration $config): array
    {
        $docker = DockerServer::create($config);

        return [[
            self::OK,
            'Docker',
            $docker->isAvailable() ? 'available' : 'not available',
            $docker->isAvailable()
                ? 'Optional. The native binary is the primary path.'
                : 'Optional, and not needed unless you pass --docker.',
        ]];
    }

    private function badge(string $status): string
    {
        return match ($status) {
            self::OK => '<fg=green>[ok]</>  ',
            self::WARN => '<fg=yellow>[warn]</>',
            default => '<fg=red>[fail]</>',
        };
    }
}
