<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Laravel\Console;

use Illuminate\Console\Command;
use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Proxy\Proxy;
use Mpge\Toxiproxy\Server\ServerRegistry;
use Mpge\Toxiproxy\Server\ToxiproxyProbe;

final class StatusCommand extends Command
{
    protected $signature = 'toxiproxy:status';

    protected $description = 'Show whether Toxiproxy is running and what it is proxying';

    public function handle(Configuration $config): int
    {
        $version = (new ToxiproxyProbe($config))->version();

        if ($version === null) {
            $this->components->warn(sprintf('Toxiproxy is not running on %s.', $config->apiUrl()));
            $this->line('  Start it with <comment>php artisan toxiproxy:start</comment>.');

            return self::FAILURE;
        }

        $record = ServerRegistry::inHome($config->homeDirectory())->find($config->host, $config->port);

        $this->components->info(sprintf('Toxiproxy %s on %s', $version, $config->apiUrl()));
        $this->components->twoColumnDetail('Ownership', $record === null ? 'external' : sprintf('ours, pid %d', $record->pid));

        try {
            $proxies = (new ToxiproxyClient($config->apiUrl()))->proxies();
        } catch (ToxiproxyException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($proxies->isEmpty()) {
            $this->components->twoColumnDetail('Proxies', 'none');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Proxy', 'Connect to', 'Upstream', 'Enabled', 'Toxics'],
            array_map(static fn (Proxy $proxy): array => [
                $proxy->name(),
                $proxy->listen(),
                $proxy->upstreamAddress(),
                $proxy->isEnabled() ? 'yes' : 'no',
                $proxy->toxics()->isEmpty() ? '-' : implode(', ', $proxy->toxics()->names()),
            ], $proxies->all()),
        );

        return self::SUCCESS;
    }
}
