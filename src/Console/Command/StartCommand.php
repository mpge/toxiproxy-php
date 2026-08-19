<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Server\Server;
use Mpge\Toxiproxy\Server\ServerRegistry;
use Mpge\Toxiproxy\Server\ToxiproxyServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'start',
    description: 'Start a Toxiproxy server, installing the binary first if needed',
)]
final class StartCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions();

        $this
            ->addOption('foreground', null, InputOption::VALUE_NONE, 'Stay attached and stream the server log until interrupted')
            ->setHelp(<<<'HELP'
            Starts the Toxiproxy server and returns, leaving it running in the
            background. Stop it later with <info>toxiproxy-php stop</info>.

            If something is already answering on the API port, this reports that and exits
            successfully without starting a second one.

            With <comment>--foreground</comment> the server stays attached to this terminal and its log is
            streamed; Ctrl-C then stops it.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);
        $server = $this->server($input, $config);

        if ($server->isRunning()) {
            $io->success(sprintf('Toxiproxy is already running on %s.', $config->apiUrl()));

            $record = ServerRegistry::inHome($config->homeDirectory())->find($config->host, $config->port);

            $io->writeln($record === null
                ? '  Started outside this package, so `stop` will leave it alone.'
                : sprintf('  Started by this package earlier (pid %d). Stop it with `toxiproxy-php stop`.', $record->pid));

            return self::SUCCESS;
        }

        $foreground = $input->getOption('foreground') === true;

        try {
            $server->start(detached: ! $foreground);
        } catch (ToxiproxyException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $version = $server->client()->version();

        $io->success(sprintf('Toxiproxy %s listening on %s', $version, $config->apiUrl()));

        if ($server instanceof ToxiproxyServer) {
            $pid = $server->pid();

            if ($pid !== null) {
                $io->writeln(sprintf('  pid  <info>%d</info>', $pid));
            }

            $io->writeln(sprintf('  log  <info>%s</info>', $server->logFile()));
        }

        if (! $foreground) {
            return self::SUCCESS;
        }

        $io->writeln('');
        $io->writeln('<comment>Attached. Press Ctrl-C to stop the server.</comment>');
        $io->writeln('');

        return $this->streamUntilInterrupted($server, $output);
    }

    /**
     * Follow the log until the process goes away or the user interrupts.
     */
    private function streamUntilInterrupted(Server $server, OutputInterface $output): int
    {
        $seen = 0;

        while ($server->isRunning()) {
            $logs = $server->logs();

            if (strlen($logs) > $seen) {
                $output->write(substr($logs, $seen));
                $seen = strlen($logs);
            }

            usleep(250_000);
        }

        return self::SUCCESS;
    }
}
