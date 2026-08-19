<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console\Command;

use Mpge\Toxiproxy\Exception\ServerException;
use Mpge\Toxiproxy\Server\DockerServer;
use Mpge\Toxiproxy\Server\ToxiproxyServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stop',
    description: 'Stop a Toxiproxy server this package started',
)]
final class StopCommand extends ToxiproxyCommand
{
    protected function configure(): void
    {
        $this->addConnectionOptions();

        $this->setHelp(<<<'HELP'
        Stops the Toxiproxy server recorded for this endpoint.

        A server this package did not start has no record, and this command refuses to
        touch it. That is deliberate: your docker-compose Toxiproxy, or one a colleague
        left running, is not ours to kill.
        HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->configuration($input);
        $server = $this->server($input, $config);

        if ($server instanceof DockerServer) {
            $io->warning(sprintf(
                'Docker containers are stopped with docker, not through this command: '
                .'docker stop %s',
                $server->containerName(),
            ));

            return self::FAILURE;
        }

        if (! $server instanceof ToxiproxyServer) {
            $io->error('This server type cannot be stopped from the CLI.');

            return self::FAILURE;
        }

        try {
            $stopped = $server->stopRecorded();
        } catch (ServerException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($stopped) {
            $io->success(sprintf('Stopped the Toxiproxy server on %s.', $config->apiUrl()));

            return self::SUCCESS;
        }

        $io->writeln(sprintf('<comment>Nothing to stop on %s.</comment>', $config->apiUrl()));

        return self::SUCCESS;
    }
}
