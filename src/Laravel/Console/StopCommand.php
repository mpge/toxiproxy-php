<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Laravel\Console;

use Illuminate\Console\Command;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ServerException;
use Mpge\Toxiproxy\Server\ToxiproxyServer;

final class StopCommand extends Command
{
    protected $signature = 'toxiproxy:stop';

    protected $description = 'Stop a Toxiproxy server this application started';

    public function handle(ToxiproxyServer $server, Configuration $config): int
    {
        try {
            $stopped = $server->stopRecorded();
        } catch (ServerException $e) {
            // Raised when a server is running that we have no record of
            // starting. Killing it is not ours to do.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($stopped) {
            $this->components->info(sprintf('Stopped Toxiproxy on %s.', $config->apiUrl()));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Nothing to stop on %s.', $config->apiUrl()));

        return self::SUCCESS;
    }
}
