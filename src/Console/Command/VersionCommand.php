<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Console\Application;
use Mpge\Toxiproxy\Server\BinaryManager;
use Mpge\Toxiproxy\Server\ToxiproxyProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'version',
    description: 'Show the package, binary and running server versions',
)]
final class VersionCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions();

        $this->setHelp(<<<'HELP'
        Three versions matter and they can disagree:

          package  this Composer package
          binary   the toxiproxy-server on disk
          server   whatever is answering on the API port right now

        A mismatch between binary and server usually means you are talking to a
        Toxiproxy somebody else started.
        HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);
        $binaries = BinaryManager::create($config);

        $binaryPath = $binaries->locate();
        $binaryVersion = $binaryPath === null ? null : $binaries->installedVersion($binaryPath);
        $serverVersion = (new ToxiproxyProbe($config))->version();

        $io->writeln(sprintf('package  <info>%s</info>  mpge/toxiproxy-php', Application::VERSION));

        $io->writeln($binaryPath === null
            ? 'binary   <comment>not installed</comment>'
            : sprintf('binary   <info>%s</info>  %s', $binaryVersion ?? 'unknown', $binaryPath));

        $io->writeln($serverVersion === null
            ? sprintf('server   <comment>not running</comment>  %s', $config->apiUrl())
            : sprintf('server   <info>%s</info>  %s', $serverVersion, $config->apiUrl()));

        if ($binaryVersion !== null && $serverVersion !== null && $binaryVersion !== $serverVersion) {
            $io->writeln('');
            $io->writeln(sprintf(
                '<comment>The running server (%s) is not the binary on disk (%s), so it was started by something else.</comment>',
                $serverVersion,
                $binaryVersion,
            ));
        }

        return self::SUCCESS;
    }
}
