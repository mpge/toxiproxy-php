<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Proxy\Proxy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'proxies',
    description: 'List the proxies on the running server and the toxics on them',
)]
final class ProxiesCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions(includeServerOptions: false);

        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);

        try {
            $proxies = (new ToxiproxyClient($config->apiUrl()))->proxies();
        } catch (ToxiproxyException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode($proxies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($proxies->isEmpty()) {
            $io->writeln(sprintf('<comment>No proxies on %s.</comment>', $config->apiUrl()));

            return self::SUCCESS;
        }

        foreach ($proxies as $proxy) {
            $this->renderProxy($io, $proxy);
        }

        return self::SUCCESS;
    }

    private function renderProxy(SymfonyStyle $io, Proxy $proxy): void
    {
        $io->writeln(sprintf(
            '<info>%s</info>  %s -> %s  %s',
            $proxy->name(),
            $proxy->listen(),
            $proxy->upstreamAddress(),
            $proxy->isEnabled() ? '' : '<comment>[disabled]</comment>',
        ));

        if ($proxy->toxics()->isEmpty()) {
            $io->writeln('  <comment>no toxics</comment>');
            $io->writeln('');

            return;
        }

        foreach ($proxy->toxics() as $toxic) {
            $io->writeln(sprintf(
                '  %-10s %s',
                $toxic->stream->value,
                $toxic->describe(),
            ));
        }

        $io->writeln('');
    }
}
