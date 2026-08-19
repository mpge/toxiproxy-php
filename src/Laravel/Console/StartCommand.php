<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Server\ServerRegistry;
use Mpge\Toxiproxy\Server\ToxiproxyServer;

final class StartCommand extends Command
{
    protected $signature = 'toxiproxy:start
        {--foreground : Stay attached and stream the server log until interrupted}';

    protected $description = 'Start Toxiproxy and create the proxies declared in config/toxiproxy.php';

    public function handle(ToxiproxyServer $server, Configuration $config, ConfigRepository $laravelConfig): int
    {
        if ($server->isRunning()) {
            $this->components->info(sprintf('Toxiproxy is already running on %s.', $config->apiUrl()));

            $record = ServerRegistry::inHome($config->homeDirectory())->find($config->host, $config->port);

            $this->line($record === null
                ? '  Started outside this application, so toxiproxy:stop will leave it alone.'
                : sprintf('  Started by this package earlier (pid %d).', $record->pid));
        } else {
            try {
                $server->start(detached: $this->option('foreground') !== true);
            } catch (ToxiproxyException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                'Toxiproxy %s listening on %s',
                $server->client()->version(),
                $config->apiUrl(),
            ));
        }

        return $this->createConfiguredProxies($server, $laravelConfig);
    }

    private function createConfiguredProxies(ToxiproxyServer $server, ConfigRepository $laravelConfig): int
    {
        /** @var mixed $declared */
        $declared = $laravelConfig->get('toxiproxy.proxies', []);

        if (! is_array($declared) || $declared === []) {
            return self::SUCCESS;
        }

        $client = $server->client();
        $rows = [];

        foreach ($declared as $name => $definition) {
            if (! is_string($name)) {
                continue;
            }

            $upstream = is_array($definition) ? ($definition['upstream'] ?? null) : $definition;
            $listen = is_array($definition) ? ($definition['listen'] ?? null) : null;

            if (! is_string($upstream) || $upstream === '') {
                $this->components->error(sprintf('Proxy "%s" has no upstream address.', $name));

                return self::FAILURE;
            }

            try {
                $proxy = $client->ensureProxy($name, $upstream, is_string($listen) ? $listen : null);
            } catch (ToxiproxyException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $rows[] = [$proxy->name(), $proxy->listen(), $proxy->upstreamAddress()];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Proxy', 'Connect to', 'Upstream'], $rows);
        }

        return self::SUCCESS;
    }
}
