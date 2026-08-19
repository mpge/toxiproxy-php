<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Integration;

use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicDirection;
use Mpge\Toxiproxy\Toxic\ToxicType;
use RuntimeException;

/**
 * The scoped helpers, which are the part of this package a test suite touches
 * most and the part where a leak does the most damage: a toxic left behind
 * breaks every test that runs after it, usually somewhere unrelated.
 */
final class ScopedChaosTest extends IntegrationTestCase
{
    public function test_with_latency_applies_and_then_removes_the_toxic(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-latency', $upstream);

        $inside = $proxy->withLatency(400, fn (): float => $this->roundTripMilliseconds($proxy->listen(), $server));

        self::assertGreaterThan(300, $inside);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
        self::assertLessThan(300, $this->roundTripMilliseconds($proxy->listen(), $server));
    }

    public function test_the_callbacks_return_value_comes_back(): void
    {
        [, $upstream] = $this->echoServer();

        $result = $this->toxiproxy()
            ->proxy('scoped-return', $upstream)
            ->withLatency(10, static fn (): array => ['answer' => 42]);

        self::assertSame(['answer' => 42], $result);
    }

    /**
     * A failing assertion inside the block throws. If the toxic survived that,
     * one red test would cascade into a suite full of them.
     */
    public function test_a_throwing_callback_still_restores_the_proxy(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-throws', $upstream);

        try {
            $proxy->withLatency(1000, static function (): void {
                throw new RuntimeException('assertion failed');
            });
            self::fail('The exception should have propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('assertion failed', $e->getMessage());
        }

        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    public function test_a_toxic_that_existed_before_the_block_survives_it(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-preexisting', $upstream);

        $proxy->bandwidth(500);

        $proxy->withLatency(100, static fn (): null => null);

        $after = $proxy->refresh();

        self::assertSame(['bandwidth_downstream'], $after->toxics()->names());
        self::assertSame(500, $after->toxic('bandwidth_downstream')?->attribute('rate'));
    }

    /**
     * Restore means restore: a toxic the block changed goes back to what it was,
     * not to whatever the block left behind.
     */
    public function test_a_toxic_the_block_modified_is_put_back(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-modified', $upstream);

        $proxy->bandwidth(500);

        $proxy->withLatency(100, static function () use ($proxy): void {
            $proxy->bandwidth(1);
        });

        self::assertSame(500, $proxy->refresh()->toxic('bandwidth_downstream')?->attribute('rate'));
    }

    public function test_a_toxic_the_block_deleted_comes_back(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-deleted', $upstream);

        $proxy->bandwidth(500);

        $proxy->withLatency(100, static function () use ($proxy): void {
            $proxy->removeToxic('bandwidth_downstream');
        });

        self::assertTrue($proxy->refresh()->toxics()->has('bandwidth_downstream'));
    }

    public function test_nested_scopes_unwind_in_order(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-nested', $upstream);

        $proxy->withLatency(50, function () use ($proxy): void {
            self::assertTrue($proxy->refresh()->toxics()->has('latency_downstream'));

            $proxy->withBandwidth(100, function () use ($proxy): void {
                $names = $proxy->refresh()->toxics()->names();
                sort($names);
                self::assertSame(['bandwidth_downstream', 'latency_downstream'], $names);
            });

            self::assertSame(['latency_downstream'], $proxy->refresh()->toxics()->names());
        });

        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    public function test_with_bandwidth_throttles_only_inside_the_block(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-bandwidth', $upstream);

        $rate = $proxy->withBandwidth(64, static fn (): ?int => $proxy->refresh()->toxic('bandwidth_downstream')?->attribute('rate'));

        self::assertSame(64, $rate);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    public function test_with_timeout_and_with_packet_loss_clean_up_after_themselves(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-others', $upstream);

        $proxy->withTimeout(200, static fn (): null => null);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());

        $proxy->withPacketLoss(0.5, static fn (): null => null);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());

        $proxy->withLimitData(1024, static fn (): null => null);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());

        $proxy->withSlowClose(100, static fn (): null => null);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());

        $proxy->withSlicer(64, static fn (): null => null);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());

        $proxy->withResetPeer(0, static fn (): null => null);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    public function test_a_directional_scope_applies_to_the_pinned_stream(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-directional', $upstream);

        $stream = $proxy->upstream()->withLatency(
            50,
            static fn (): ?ToxicDirection => $proxy->refresh()->toxic('latency_upstream')?->stream,
        );

        self::assertSame(ToxicDirection::Upstream, $stream);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    public function test_with_toxics_applies_an_arbitrary_combination(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('scoped-combo', $upstream);

        $names = $proxy->withToxics([
            Toxic::make(ToxicType::Latency, ['latency' => 25]),
            Toxic::make(ToxicType::Bandwidth, ['rate' => 128], ToxicDirection::Upstream),
        ], static function () use ($proxy): array {
            $names = $proxy->refresh()->toxics()->names();
            sort($names);

            return $names;
        });

        self::assertSame(['bandwidth_upstream', 'latency_downstream'], $names);
        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    // ------------------------------------------------- the PHPUnit trait

    public function test_the_trait_helpers_work_end_to_end(): void
    {
        [$server, $upstream] = $this->echoServer();
        $this->proxy('trait-helpers', $upstream);

        $slow = $this->withLatency('trait-helpers', 400, fn (): float => $this->roundTripMilliseconds(
            $this->proxy('trait-helpers')->listen(),
            $server,
        ));

        self::assertGreaterThan(300, $slow);
        self::assertTrue($this->proxy('trait-helpers')->toxics()->isEmpty());
    }

    public function test_with_service_down_makes_the_service_unreachable(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->proxy('trait-down', $upstream);
        $listen = $proxy->listen();

        $reachable = $this->withServiceDown('trait-down', static function () use ($listen): bool {
            $client = @stream_socket_client('tcp://'.$listen, $errno, $errstr, 1.0);

            if ($client === false) {
                return false;
            }

            fclose($client);

            return true;
        });

        self::assertFalse($reachable);
        self::assertTrue($this->proxy('trait-down')->refresh()->isEnabled());
    }

    public function test_the_trait_can_declare_several_proxies_at_once(): void
    {
        [, $first] = $this->echoServer();
        [, $second] = $this->echoServer();

        $proxies = $this->proxies(['trait-a' => $first, 'trait-b' => $second]);

        self::assertCount(2, $proxies);
        self::assertSame($first, $proxies['trait-a']->upstreamAddress());
        self::assertGreaterThan(0, $proxies['trait-b']->port());
    }
}
