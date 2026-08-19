<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Integration;

use Mpge\Toxiproxy\Exception\ApiException;
use Mpge\Toxiproxy\Exception\ProxyNotFoundException;
use Mpge\Toxiproxy\Proxy\ProxyDefinition;
use RuntimeException;

/**
 * Proxy CRUD against a real server.
 */
final class ProxyLifecycleTest extends IntegrationTestCase
{
    public function test_the_server_reports_a_version(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $this->toxiproxy()->version());
    }

    public function test_it_creates_and_deletes_a_proxy(): void
    {
        [, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->createProxy('crud', $upstream);

        self::assertSame('crud', $proxy->name());
        self::assertSame($upstream, $proxy->upstreamAddress());
        self::assertTrue($proxy->isEnabled());
        self::assertTrue($this->toxiproxy()->client()->hasProxy('crud'));

        $proxy->delete();

        self::assertFalse($this->toxiproxy()->client()->hasProxy('crud'));
    }

    /**
     * Toxiproxy binds the socket and reports the port it was given, which is
     * why this package asks for port 0 instead of guessing one in PHP.
     */
    public function test_an_omitted_listen_address_gets_a_real_port_from_the_server(): void
    {
        [$server, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->createProxy('auto-port', $upstream);

        self::assertGreaterThan(0, $proxy->port());
        self::assertSame('127.0.0.1', $proxy->host());
        self::assertSame($proxy->host().':'.$proxy->port(), $proxy->listen());

        // The reported port is the one that actually accepts connections.
        [$client] = $this->connectThrough($proxy->listen(), $server);
        self::assertIsResource($client);
    }

    public function test_two_automatic_ports_do_not_collide(): void
    {
        [, $upstream] = $this->echoServer();

        $first = $this->toxiproxy()->createProxy('auto-a', $upstream);
        $second = $this->toxiproxy()->createProxy('auto-b', $upstream);

        self::assertNotSame($first->port(), $second->port());
    }

    public function test_an_explicit_listen_port_is_honoured(): void
    {
        [, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->createProxy('explicit', $upstream, '127.0.0.1:21999');

        self::assertSame(21999, $proxy->port());
    }

    public function test_creating_a_duplicate_name_is_rejected_by_the_server(): void
    {
        [, $upstream] = $this->echoServer();

        $this->toxiproxy()->createProxy('duplicate', $upstream);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('proxy already exists');

        $this->toxiproxy()->createProxy('duplicate', $upstream);
    }

    /**
     * The facade's proxy() is the one tests use, and a test file that runs
     * twice must not fail the second time.
     */
    public function test_getting_the_same_proxy_twice_is_idempotent(): void
    {
        [, $upstream] = $this->echoServer();

        $first = $this->toxiproxy()->proxy('idempotent', $upstream);
        $second = $this->toxiproxy()->proxy('idempotent', $upstream);

        self::assertSame($first->listen(), $second->listen());
        self::assertCount(1, $this->toxiproxy()->proxies());
    }

    public function test_changing_the_upstream_rebuilds_the_proxy(): void
    {
        [, $first] = $this->echoServer();
        [, $second] = $this->echoServer();

        $this->toxiproxy()->proxy('moving', $first);
        $moved = $this->toxiproxy()->proxy('moving', $second);

        self::assertSame($second, $moved->upstreamAddress());
        self::assertCount(1, $this->toxiproxy()->proxies());
    }

    public function test_a_missing_proxy_raises_a_typed_exception(): void
    {
        $this->expectException(ProxyNotFoundException::class);

        $this->toxiproxy()->client()->proxy('never-created');
    }

    public function test_disabling_a_proxy_severs_the_connection(): void
    {
        [$server, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->proxy('togglable', $upstream);

        [$client] = $this->connectThrough($proxy->listen(), $server);
        self::assertIsResource($client);

        $proxy->disable();
        self::assertFalse($proxy->isEnabled());
        self::assertFalse($this->canConnect($proxy->listen()));

        $proxy->enable();
        self::assertTrue($proxy->isEnabled());
        self::assertTrue($this->canConnect($proxy->listen()));
    }

    public function test_down_severs_the_connection_and_restores_it(): void
    {
        [, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->proxy('down-scope', $upstream);
        $listen = $proxy->listen();

        $reachableInside = $proxy->down(fn (): bool => $this->canConnect($listen));

        self::assertFalse($reachableInside);
        self::assertTrue($proxy->refresh()->isEnabled());
        self::assertTrue($this->canConnect($listen));
    }

    public function test_down_restores_the_proxy_when_the_callback_throws(): void
    {
        [, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->proxy('down-throws', $upstream);

        /** @var list<string> $steps */
        $steps = [];

        try {
            $proxy->down(static function () use (&$steps): void {
                $steps[] = 'inside';

                throw new RuntimeException('assertion failed inside the block');
            });
        } catch (RuntimeException) {
            $steps[] = 'propagated';
        }

        self::assertSame(['inside', 'propagated'], $steps);
        self::assertTrue($proxy->refresh()->isEnabled());
        self::assertTrue($this->canConnect($proxy->listen()));
    }

    public function test_updating_the_listen_address_moves_the_proxy(): void
    {
        [$server, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->proxy('movable', $upstream);
        $original = $proxy->listen();

        $proxy->update(listen: '127.0.0.1:21998');

        self::assertSame(21998, $proxy->port());
        self::assertFalse($this->canConnect($original));

        [$client] = $this->connectThrough($proxy->listen(), $server);
        self::assertIsResource($client);
    }

    public function test_populate_creates_a_whole_set_at_once(): void
    {
        [, $first] = $this->echoServer();
        [, $second] = $this->echoServer();

        $proxies = $this->toxiproxy()->populate([
            new ProxyDefinition('bulk-a', '127.0.0.1:21990', $first),
            new ProxyDefinition('bulk-b', '127.0.0.1:21991', $second),
        ]);

        self::assertCount(2, $proxies);
        self::assertSame(['bulk-a', 'bulk-b'], $proxies->names());
        self::assertSame(21990, $proxies->get('bulk-a')->port());
        self::assertCount(2, $this->toxiproxy()->proxies());
    }

    public function test_listing_proxies_reports_their_toxics(): void
    {
        [, $upstream] = $this->echoServer();

        $this->toxiproxy()->proxy('listed', $upstream)->latency(10);

        $listed = $this->toxiproxy()->proxies()->get('listed');

        self::assertCount(1, $listed->toxics());
        self::assertTrue($listed->toxics()->has('latency_downstream'));
        self::assertCount(1, $this->toxiproxy()->proxies()->poisoned());
    }

    public function test_reset_clears_toxics_and_re_enables_without_deleting_proxies(): void
    {
        [, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->proxy('resettable', $upstream);
        $proxy->latency(50);
        $proxy->disable();

        $this->toxiproxy()->reset();

        $refreshed = $proxy->refresh();

        self::assertTrue($refreshed->isEnabled());
        self::assertTrue($refreshed->toxics()->isEmpty());
        self::assertCount(1, $this->toxiproxy()->proxies());
    }

    public function test_flush_deletes_every_proxy(): void
    {
        [, $upstream] = $this->echoServer();

        $this->toxiproxy()->proxy('flush-a', $upstream);
        $this->toxiproxy()->proxy('flush-b', $upstream);

        $this->toxiproxy()->flush();

        self::assertCount(0, $this->toxiproxy()->proxies());
    }

    private function canConnect(string $listen, float $timeout = 1.0): bool
    {
        $client = @stream_socket_client('tcp://'.$listen, $errno, $errstr, $timeout);

        if ($client === false) {
            return false;
        }

        fclose($client);

        return true;
    }
}
