<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Server\BinaryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'install',
    description: 'Download the official Toxiproxy server binary for this platform',
)]
final class InstallCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions();

        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-download even if the binary is already cached')
            ->addOption('no-verify', null, InputOption::VALUE_NONE, 'Skip sha256 verification against the release checksums')
            ->setHelp(<<<'HELP'
            Downloads the toxiproxy-server binary Shopify publishes for this OS and
            architecture, verifies its sha256 against the release checksums file, and
            caches it outside vendor/ so every project on this machine shares one copy.

            Nothing is compiled and nothing is reimplemented: this is the same binary you
            would get from the GitHub release page.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);

        if ($input->getOption('no-verify') === true) {
            $config = $config->withVerifyChecksums(false);
            $io->warning('Checksum verification disabled. The download will not be validated.');
        }

        $binaries = BinaryManager::create($config);
        $platform = $binaries->platform();

        try {
            $release = $binaries->release();
        } catch (ToxiproxyException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $force = $input->getOption('force') === true;
        $destination = $binaries->cachedPath($release);

        if (! $force && is_file($destination)) {
            $io->success(sprintf('Toxiproxy %s is already installed.', $release->version));
            $io->writeln('  '.$destination);
            $io->writeln('');
            $io->writeln('Re-download it with <comment>--force</comment>.');

            return self::SUCCESS;
        }

        $io->writeln(sprintf('Platform  <info>%s</info>', $platform->toString()));
        $io->writeln(sprintf('Release   <info>%s</info>', $release->tag()));
        $io->writeln(sprintf('Source    <info>%s</info>', $release->serverUrl($platform)));
        $io->writeln(sprintf('Target    <info>%s</info>', $destination));
        $io->writeln('');

        $progress = $this->progressBar($output);

        try {
            $binaries->install($force, static function (int $downloaded, int $total) use ($progress): void {
                if ($total > 0 && $progress->getMaxSteps() !== $total) {
                    $progress->setMaxSteps($total);
                }

                $progress->setProgress($downloaded);
            });
        } catch (ToxiproxyException $e) {
            $progress->clear();
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $progress->finish();
        $io->writeln('');
        $io->writeln('');

        $installed = $binaries->installedVersion($destination);

        $io->success(sprintf(
            'Installed Toxiproxy %s.',
            $installed ?? $release->version,
        ));

        if ($installed !== null && $installed !== $release->version) {
            $io->warning(sprintf(
                'The binary reports version %s but %s was requested.',
                $installed,
                $release->version,
            ));
        }

        return self::SUCCESS;
    }

    private function progressBar(OutputInterface $output): ProgressBar
    {
        $progress = new ProgressBar($output);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progress->start();

        return $progress;
    }
}
