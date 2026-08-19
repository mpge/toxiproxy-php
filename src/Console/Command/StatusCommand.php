<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Server\ProcessControl;
use Mpge\Toxiproxy\Server\ServerRegistry;
use Mpge\Toxiproxy\Server\ToxiproxyProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'status',
    description: 'Show whether Toxiproxy is running and what it is proxying',
)]
final class StatusCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);
        $probe = new ToxiproxyProbe($config);
        $version = $probe->version();

        if ($version === null) {
            $io->writeln(sprintf('Toxiproxy   <comment>not running</comment> on %s', $config->apiUrl()));

            if ($probe->isPortOpen()) {
                $io->writeln('');
                $io->warning(sprintf(
                    'Something else is listening on %s:%d and it is not Toxiproxy.',
                    $config->host,
                    $config->port,
                ));
            }

            return self::FAILURE;
        }

        $registry = ServerRegistry::inHome($config->homeDirectory());
        $record = $registry->find($config->host, $config->port);

        $io->writeln(sprintf('Toxiproxy   <info>%s</info> on %s', $version, $config->apiUrl()));

        if ($record === null) {
            $io->writeln('Ownership   <comment>external</comment> (not started by this package, and will not be stopped by it)');
        } else {
            $alive = (new ProcessControl())->isAlive($record->pid);
            $io->writeln(sprintf(
                'Ownership   <info>ours</info> (pid %d%s, %s, up %ds)',
                $record->pid,
                $alive ? '' : ', <comment>gone</comment>',
                $record->detached ? 'detached' : 'attached',
                $record->uptimeSeconds(time()),
            ));
            $io->writeln(sprintf('Binary      %s', $record->binary));
        }

        try {
            $proxies = (new ToxiproxyClient($config->apiUrl()))->proxies();
        } catch (ToxiproxyException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->writeln(sprintf('Proxies     %d (%d with toxics)', count($proxies), count($proxies->poisoned())));

        if (! $proxies->isEmpty()) {
            $io->writeln('');
            $io->table(
                ['Proxy', 'Listen', 'Upstream', 'Enabled', 'Toxics'],
                array_map(static fn ($proxy): array => [
                    $proxy->name(),
                    $proxy->listen(),
                    $proxy->upstreamAddress(),
                    $proxy->isEnabled() ? 'yes' : 'no',
                    $proxy->toxics()->isEmpty() ? '-' : implode(', ', $proxy->toxics()->names()),
                ], $proxies->all()),
            );
        }

        return self::SUCCESS;
    }
}
