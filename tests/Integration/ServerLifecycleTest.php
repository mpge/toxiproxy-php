<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Integration;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ServerException;
use Mpge\Toxiproxy\Proxy\PortAllocator;
use Mpge\Toxiproxy\Server\ProcessControl;
use Mpge\Toxiproxy\Server\ToxiproxyServer;
use Mpge\Toxiproxy\Toxiproxy;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * Starting and stopping real server processes.
 *
 * Each test uses its own port so nothing here can disturb the shared server the
 * other integration tests run against.
 */
final class ServerLifecycleTest extends TestCase
{
    /** @var list<ToxiproxyServer> */
    private array $started = [];

    #[After]
    protected function stopEverythingStarted(): void
    {
        foreach ($this->started as $server) {
            try {
                $server->stop(2.0);
            } catch (\Throwable) {
                // Best effort: the point of the cleanup is to leave no orphans.
            }
        }

        $this->started = [];
    }

    public function test_it_installs_starts_and_stops(): void
    {
        $server = $this->server();

        $binary = $server->ensureInstalled();

        self::assertFileExists($binary);
        self::assertFalse($server->isRunning());

        $server->start();

        self::assertTrue($server->isRunning());
        self::assertTrue($server->ownsProcess());
        self::assertNotNull($server->pid());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $server->client()->version());

        self::assertTrue($server->stop());
        self::assertFalse($server->isRunning());
        self::assertFalse($server->ownsProcess());
    }

    /**
     * The central safety property: a server this package did not start is used,
     * not duplicated, and never killed.
     */
    public function test_an_existing_server_is_adopted_and_left_running(): void
    {
        $config = $this->configuration();

        $owner = ToxiproxyServer::create($config);
        $this->started[] = $owner;
        $owner->start();

        $adopter = ToxiproxyServer::create($config);

        $adopter->start();

        self::assertTrue($adopter->isRunning());
        self::assertFalse($adopter->ownsProcess(), 'A server we did not start must not be reported as ours.');

        self::assertFalse($adopter->stop(), 'stop() must be a no-op for a server we did not start.');
        self::assertTrue($adopter->isRunning(), 'The adopted server must survive our stop().');

        self::assertTrue($owner->stop());
    }

    public function test_starting_twice_does_not_spawn_a_second_process(): void
    {
        $server = $this->server();

        $server->start();
        $firstPid = $server->pid();

        $server->start();

        self::assertSame($firstPid, $server->pid());
        self::assertTrue($server->isRunning());
    }

    /**
     * Refusing an occupied port with a clear message beats a Go process dying
     * on startup with "address already in use" buried in its log.
     */
    public function test_a_port_held_by_something_else_produces_a_useful_error(): void
    {
        $port = PortAllocator::free();
        $blocker = stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errno, $errstr);

        self::assertNotFalse($blocker, 'Could not occupy a port for the test.');

        try {
            $server = ToxiproxyServer::create($this->configuration($port));

            $this->expectException(ServerException::class);
            $this->expectExceptionMessage('already in use');

            $server->start();
        } finally {
            fclose($blocker);
        }
    }

    public function test_a_started_server_is_recorded_so_a_later_process_can_stop_it(): void
    {
        $server = $this->server();
        $server->start();

        $record = $server->registry()->find($server->config()->host, $server->config()->port);

        self::assertNotNull($record);
        self::assertSame($server->pid(), $record->pid);
        self::assertTrue((new ProcessControl())->isAlive($record->pid));
        self::assertFileExists($record->binary);
    }

    public function test_stopping_removes_the_record(): void
    {
        $server = $this->server();
        $server->start();
        $server->stop();

        self::assertNull($server->registry()->find($server->config()->host, $server->config()->port));
    }

    /**
     * With no record and a live server, stopping is refused rather than
     * guessing. This is what protects a docker-compose Toxiproxy from a stray
     * `toxiproxy-php stop`.
     */
    public function test_stopping_an_unrecorded_running_server_is_refused(): void
    {
        $config = $this->configuration();

        $owner = ToxiproxyServer::create($config);
        $this->started[] = $owner;
        $owner->start();

        $stranger = ToxiproxyServer::create($config);
        $stranger->registry()->forget($config->host, $config->port);

        try {
            $stranger->stopRecorded();
            self::fail('Expected a refusal to stop a server we have no record of.');
        } catch (ServerException $e) {
            self::assertStringContainsString('did not start it', $e->getMessage());
        }

        self::assertTrue($owner->isRunning());
    }

    public function test_stopping_when_nothing_runs_is_not_an_error(): void
    {
        self::assertFalse($this->server()->stopRecorded());
    }

    public function test_a_detached_server_outlives_the_object_that_started_it(): void
    {
        $config = $this->configuration();

        $starter = ToxiproxyServer::create($config);
        $this->started[] = $starter;
        $starter->start(detached: true);

        self::assertTrue($starter->isRunning());

        // A fresh object, as a later CLI invocation would have, finds it
        // through the registry and can stop it.
        $later = ToxiproxyServer::create($config);

        self::assertTrue($later->isRunning());
        self::assertTrue($later->stopRecorded());
        self::assertFalse($later->isRunning());

        // Already stopped, so the after-hook has nothing left to do.
        array_pop($this->started);
    }

    public function test_the_facade_starts_and_stops_the_same_way(): void
    {
        $config = $this->configuration();

        $toxiproxy = Toxiproxy::make($config)->start();

        self::assertTrue($toxiproxy->isRunning());
        self::assertTrue($toxiproxy->ownsServer());

        self::assertTrue($toxiproxy->stop());
        self::assertFalse($toxiproxy->isRunning());
    }

    public function test_connect_never_starts_anything(): void
    {
        $toxiproxy = Toxiproxy::connect($this->configuration());

        self::assertFalse($toxiproxy->isRunning());
        self::assertNull($toxiproxy->server());
        self::assertFalse($toxiproxy->ownsServer());
        self::assertFalse($toxiproxy->stop());
    }

    public function test_the_server_writes_a_log_that_can_be_read_back(): void
    {
        // The rest of the suite runs at "warn", where the startup banner is
        // correctly suppressed and the log would legitimately be empty.
        $server = ToxiproxyServer::create($this->configuration()->withLogLevel('info'));
        $this->started[] = $server;
        $server->start(detached: true);

        // The server logs its startup banner, so something must be there.
        $deadline = microtime(true) + 5;
        $logs = '';

        while (microtime(true) < $deadline && trim($logs) === '') {
            $logs = $server->logs();
            usleep(100_000);
        }

        self::assertNotSame('', trim($logs), 'Expected the detached server to write to its log file.');
        self::assertFileExists($server->logFile());
    }

    private function server(?int $port = null): ToxiproxyServer
    {
        $server = ToxiproxyServer::create($this->configuration($port));
        $this->started[] = $server;

        return $server;
    }

    private function configuration(?int $port = null): Configuration
    {
        return Configuration::fromEnvironment()
            ->withPort($port ?? PortAllocator::free())
            ->withLogLevel('warn')
            ->withStartTimeout(30.0);
    }
}
