<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Laravel\Console;

use Illuminate\Console\Command;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Server\BinaryManager;

final class InstallCommand extends Command
{
    protected $signature = 'toxiproxy:install
        {--force : Re-download even if the binary is already cached}';

    protected $description = 'Download the official Toxiproxy server binary for this platform';

    public function handle(Configuration $config): int
    {
        $binaries = BinaryManager::create($config);
        $platform = $binaries->platform();

        try {
            $release = $binaries->release();
        } catch (ToxiproxyException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $destination = $binaries->cachedPath($release);
        $force = $this->option('force') === true;

        if (! $force && is_file($destination)) {
            $this->components->info(sprintf('Toxiproxy %s is already installed.', $release->version));
            $this->components->twoColumnDetail('Path', $destination);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Platform', $platform->toString());
        $this->components->twoColumnDetail('Release', $release->tag());
        $this->components->twoColumnDetail('Source', $release->serverUrl($platform));

        $bar = $this->output->createProgressBar();
        $bar->start();

        try {
            $binaries->install($force, static function (int $downloaded, int $total) use ($bar): void {
                if ($total > 0 && $bar->getMaxSteps() !== $total) {
                    $bar->setMaxSteps($total);
                }

                $bar->setProgress($downloaded);
            });
        } catch (ToxiproxyException $e) {
            $bar->clear();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->info(sprintf(
            'Installed Toxiproxy %s.',
            $binaries->installedVersion($destination) ?? $release->version,
        ));

        return self::SUCCESS;
    }
}
