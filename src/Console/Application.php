<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Console;

use Mpge\Toxiproxy\Console\Command\DoctorCommand;
use Mpge\Toxiproxy\Console\Command\InstallCommand;
use Mpge\Toxiproxy\Console\Command\ProxiesCommand;
use Mpge\Toxiproxy\Console\Command\ResetCommand;
use Mpge\Toxiproxy\Console\Command\StartCommand;
use Mpge\Toxiproxy\Console\Command\StatusCommand;
use Mpge\Toxiproxy\Console\Command\StopCommand;
use Mpge\Toxiproxy\Console\Command\UpdateCommand;
use Mpge\Toxiproxy\Console\Command\VersionCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The `vendor/bin/toxiproxy-php` command line.
 */
final class Application extends BaseApplication
{
    public const NAME = 'toxiproxy-php';

    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommands([
            new InstallCommand(),
            new StartCommand(),
            new StopCommand(),
            new StatusCommand(),
            new VersionCommand(),
            new ProxiesCommand(),
            new ResetCommand(),
            new UpdateCommand(),
            new DoctorCommand(),
        ]);
    }

    public function getLongVersion(): string
    {
        return sprintf(
            '<info>%s</info> version <comment>%s</comment>  Toxiproxy for PHP, batteries included',
            self::NAME,
            self::VERSION,
        );
    }
}
