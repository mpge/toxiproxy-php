<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Server\ProcessManager;
use Mpge\Toxiproxy\Server\ServerRegistry;
use PHPUnit\Framework\TestCase;

final class ProcessManagerTest extends TestCase
{
    public function test_it_builds_the_command_upstream_actually_accepts(): void
    {
        $manager = $this->manager(new Configuration(host: '0.0.0.0', port: 9474));

        self::assertSame(
            ['/opt/toxiproxy-server', '-host', '0.0.0.0', '-port', '9474'],
            $manager->command('/opt/toxiproxy-server'),
        );
    }

    /**
     * Toxiproxy has no flag for its log level; it reads LOG_LEVEL from the
     * environment. Passing --log-level would be silently ignored.
     */
    public function test_the_log_level_goes_through_the_environment_not_a_flag(): void
    {
        $manager = $this->manager((new Configuration())->withLogLevel('debug'));

        self::assertSame(['LOG_LEVEL' => 'debug'], $manager->environment());
        self::assertNotContains('--log-level', $manager->command('/opt/toxiproxy-server'));
        self::assertNotContains('-log-level', $manager->command('/opt/toxiproxy-server'));
    }

    public function test_the_log_file_is_named_per_endpoint(): void
    {
        $log = $this->manager(new Configuration(host: '127.0.0.1', port: 9474))->defaultLogFile();

        self::assertStringContainsString('9474', $log);
        self::assertStringEndsWith('.log', $log);
    }

    public function test_a_host_with_awkward_characters_still_yields_a_usable_log_name(): void
    {
        $log = $this->manager(new Configuration(host: '::1', port: 8474))->defaultLogFile();

        self::assertStringNotContainsString(':', basename($log));
    }

    private function manager(Configuration $config): ProcessManager
    {
        return new ProcessManager(
            $config,
            ServerRegistry::inHome(sys_get_temp_dir().'/toxiproxy-php-unused'),
        );
    }
}
