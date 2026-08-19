<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'reset',
    description: 'Re-enable every proxy and remove every toxic',
)]
final class ResetCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions(includeServerOptions: false);

        $this
            ->addOption('flush', null, InputOption::VALUE_NONE, 'Delete the proxies too, not just their toxics')
            ->setHelp(<<<'HELP'
                Puts the server back to a clean state: every proxy enabled, every toxic gone.
                The proxies themselves survive, which is what you want between test cases.

                Use <comment>--flush</comment> to delete the proxies as well.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);
        $client = new ToxiproxyClient($config->apiUrl());

        try {
            if ($input->getOption('flush') === true) {
                $deleted = count($client->proxies());
                $client->deleteAllProxies();
                $io->success(sprintf('Deleted %d proxies.', $deleted));

                return self::SUCCESS;
            }

            $client->reset();
        } catch (ToxiproxyException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success('All proxies enabled and all toxics removed.');

        return self::SUCCESS;
    }
}
