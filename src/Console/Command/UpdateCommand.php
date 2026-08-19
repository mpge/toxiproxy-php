<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Server\BinaryManager;
use Mpge\Toxiproxy\Server\CurlDownloader;
use Mpge\Toxiproxy\Server\Release;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'update',
    description: 'Install the newest upstream Toxiproxy release',
)]
final class UpdateCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions();

        $this->setHelp(<<<'HELP'
            Looks up the latest Toxiproxy release on GitHub and installs it alongside
            whatever is already cached.

            Nothing is deleted and nothing switches over on its own: the version this
            package uses stays pinned until you set TOXIPROXY_VERSION or pass --release.
            A test suite whose proxy server changes underneath it on somebody else's
            release schedule is a flake waiting to happen.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);

        try {
            $latest = Release::latest(new CurlDownloader());
        } catch (ToxiproxyException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $pinned = new Release($config->wantsLatest() ? Release::DEFAULT_VERSION : $config->version);
        $binaries = BinaryManager::create($config->withVersion($latest->version));

        $io->writeln(sprintf('Latest release   <info>%s</info>', $latest->tag()));
        $io->writeln(sprintf('Package default  <info>%s</info>', Release::DEFAULT_VERSION));
        $io->writeln(sprintf('This project     <info>%s</info>', $pinned->version));
        $io->writeln('');

        if (is_file($binaries->cachedPath($latest))) {
            $io->success(sprintf('Toxiproxy %s is already installed.', $latest->version));
        } else {
            try {
                $binaries->install();
            } catch (ToxiproxyException $e) {
                $io->error($e->getMessage());

                return self::FAILURE;
            }

            $io->success(sprintf('Installed Toxiproxy %s.', $latest->version));
        }

        if (! $latest->equals($pinned)) {
            $io->writeln('To use it, set one of:');
            $io->writeln(sprintf('  <comment>TOXIPROXY_VERSION=%s</comment>', $latest->version));
            $io->writeln(sprintf('  <comment>toxiproxy-php start --release %s</comment>', $latest->version));
        }

        return self::SUCCESS;
    }
}
