<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Integration;

use Mpge\Toxiproxy\Exception\ToxicNotFoundException;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicDirection;
use Mpge\Toxiproxy\Toxic\ToxicType;

/**
 * Every toxic type, against a real server.
 *
 * Where the effect is observable in a reasonable time the test measures it
 * rather than just asserting the toxic was stored. A toxic that the API accepts
 * but that does nothing is the exact failure this package has to rule out,
 * since Go silently discards attribute keys it does not recognise.
 */
final class ToxicTest extends IntegrationTestCase
{
    public function test_latency_delays_the_round_trip(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('latency', $upstream);

        $baseline = $this->roundTripMilliseconds($proxy->listen(), $server);

        $proxy->latency(400);

        $delayed = $this->roundTripMilliseconds($proxy->listen(), $server);

        self::assertGreaterThan($baseline + 300, $delayed, sprintf(
            'Expected the round trip to grow by roughly 400ms, went from %.0fms to %.0fms.',
            $baseline,
            $delayed,
        ));
    }

    public function test_latency_accepts_jitter(): void
    {
        [, $upstream] = $this->echoServer();

        $toxic = $this->toxiproxy()->proxy('jitter', $upstream)->latency(latency: 100, jitter: 25);

        self::assertSame(100, $toxic->attribute('latency'));
        self::assertSame(25, $toxic->attribute('jitter'));
    }

    public function test_bandwidth_throttles_a_transfer(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('bandwidth', $upstream);

        // 100 KB at 50 KB/s should take about two seconds; without the toxic it
        // is effectively instant over loopback.
        $payload = str_repeat('x', 100 * 1024);

        $proxy->bandwidth(50);

        [$client, $accepted] = $this->connectThrough($proxy->listen(), $server);

        $started = microtime(true);
        fwrite($accepted, $payload);

        $read = 0;

        while ($read < strlen($payload)) {
            $chunk = fread($client, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $read += strlen($chunk);
        }

        $elapsed = microtime(true) - $started;

        self::assertSame(strlen($payload), $read);
        self::assertGreaterThan(1.0, $elapsed, sprintf('100KB at 50KB/s took only %.2fs.', $elapsed));
    }

    public function test_timeout_stops_data_and_closes_the_connection(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('timeout', $upstream);

        $proxy->timeout(300);

        [$client, $accepted] = $this->connectThrough($proxy->listen(), $server);

        fwrite($accepted, "never arrives\n");
        stream_set_timeout($client, 3);

        $received = fgets($client);

        // The toxic swallows the data and closes the connection, so the read
        // ends without ever producing the payload.
        self::assertFalse($received === "never arrives\n");
    }

    public function test_timeout_defaults_to_hanging_forever(): void
    {
        [, $upstream] = $this->echoServer();

        $toxic = $this->toxiproxy()->proxy('hang', $upstream)->timeout();

        // Zero is upstream's "never close", which is the more useful default
        // for exercising a client's own timeout handling.
        self::assertSame(0, $toxic->attribute('timeout'));
    }

    public function test_reset_peer_closes_the_connection(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('reset', $upstream);

        $proxy->resetPeer();

        [$client, $accepted] = $this->connectThrough($proxy->listen(), $server);

        fwrite($accepted, "hello\n");
        stream_set_timeout($client, 3);

        self::assertFalse(fgets($client));
    }

    public function test_slow_close_is_accepted_and_stored(): void
    {
        [, $upstream] = $this->echoServer();

        $toxic = $this->toxiproxy()->proxy('slow-close', $upstream)->slowClose(250);

        self::assertSame(ToxicType::SlowClose, $toxic->type);
        self::assertSame(250, $toxic->attribute('delay'));
    }

    public function test_limit_data_cuts_the_connection_after_the_byte_budget(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('limit', $upstream);

        $proxy->limitData(64);

        [$client, $accepted] = $this->connectThrough($proxy->listen(), $server);

        fwrite($accepted, str_repeat('y', 4096));
        stream_set_timeout($client, 3);

        $read = 0;

        while ($read < 4096) {
            $chunk = fread($client, 1024);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $read += strlen($chunk);
        }

        self::assertSame(64, $read, 'limit_data should deliver exactly its byte budget and then close.');
    }

    public function test_slicer_is_accepted_with_all_three_attributes(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('slicer', $upstream);

        $toxic = $proxy->slicer(averageSize: 64, sizeVariation: 16, delayMicroseconds: 100);

        self::assertSame(64, $toxic->attribute('average_size'));
        self::assertSame(16, $toxic->attribute('size_variation'));
        self::assertSame(100, $toxic->attribute('delay'));

        // Sliced data still arrives intact; only its framing changes.
        [$client, $accepted] = $this->connectThrough($proxy->listen(), $server);

        $payload = str_repeat('z', 512);
        fwrite($accepted, $payload);

        $read = '';
        stream_set_timeout($client, 5);

        while (strlen($read) < strlen($payload)) {
            $chunk = fread($client, 1024);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $read .= $chunk;
        }

        self::assertSame($payload, $read);
    }

    public function test_noop_passes_traffic_through_untouched(): void
    {
        [$server, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('noop', $upstream);

        $proxy->noop();

        self::assertTrue($proxy->toxics()->has('noop_downstream'));
        self::assertLessThan(1000, $this->roundTripMilliseconds($proxy->listen(), $server));
    }

    /**
     * Toxiproxy has no packet_loss toxic. Loss is expressed through toxicity,
     * the fraction of connections a toxic applies to, so this package maps it
     * onto reset_peer rather than pretending otherwise.
     */
    public function test_packet_loss_arms_a_partial_reset_peer(): void
    {
        [, $upstream] = $this->echoServer();

        $toxic = $this->toxiproxy()->proxy('loss', $upstream)->packetLoss(0.25);

        self::assertSame(ToxicType::ResetPeer, $toxic->type);
        self::assertEqualsWithDelta(0.25, $toxic->toxicity, 0.001);
    }

    public function test_toxicity_survives_the_round_trip_to_the_server(): void
    {
        [, $upstream] = $this->echoServer();

        $proxy = $this->toxiproxy()->proxy('toxicity', $upstream);
        $proxy->latency(100, toxicity: 0.5);

        $stored = $proxy->refresh()->toxic('latency_downstream');

        self::assertNotNull($stored);
        self::assertEqualsWithDelta(0.5, $stored->toxicity, 0.001);
    }

    public function test_upstream_and_downstream_toxics_coexist(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('directions', $upstream);

        $proxy->downstream()->latency(20);
        $proxy->upstream()->bandwidth(64);

        $refreshed = $proxy->refresh();

        self::assertCount(2, $refreshed->toxics());
        self::assertSame(['latency_downstream'], $refreshed->downstream()->toxics()->names());
        self::assertSame(['bandwidth_upstream'], $refreshed->upstream()->toxics()->names());
        self::assertSame(
            ToxicDirection::Upstream,
            $refreshed->toxic('bandwidth_upstream')?->stream,
        );
    }

    public function test_several_toxics_stack_on_one_proxy(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('stacked', $upstream);

        $proxy->latency(10);
        $proxy->bandwidth(1000);
        $proxy->slowClose(10);

        $names = $proxy->refresh()->toxics()->names();

        sort($names);

        self::assertSame(['bandwidth_downstream', 'latency_downstream', 'slow_close_downstream'], $names);
    }

    public function test_a_named_toxic_can_be_updated_in_place(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('updatable', $upstream);

        $proxy->latency(100, name: 'my-latency');
        $updated = $this->toxiproxy()->client()->updateToxic(
            'updatable',
            Toxic::make(ToxicType::Latency, ['latency' => 900], name: 'my-latency'),
        );

        self::assertSame(900, $updated->attribute('latency'));
        self::assertCount(1, $proxy->refresh()->toxics());
    }

    public function test_a_toxic_can_be_removed_by_name(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('removable', $upstream);

        $proxy->latency(10);
        $proxy->bandwidth(100);
        $proxy->removeToxic('latency_downstream');

        self::assertSame(['bandwidth_downstream'], $proxy->refresh()->toxics()->names());
    }

    public function test_removing_every_toxic_leaves_a_clean_proxy(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('cleanable', $upstream);

        $proxy->latency(10);
        $proxy->upstream()->bandwidth(100);
        $proxy->removeToxics();

        self::assertTrue($proxy->refresh()->toxics()->isEmpty());
    }

    public function test_removing_a_toxic_that_is_not_there_says_so(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('missing-toxic', $upstream);

        $this->expectException(ToxicNotFoundException::class);
        $this->expectExceptionMessage('does not exist on proxy "missing-toxic"');

        $proxy->removeToxic('never_added');
    }

    public function test_the_generic_api_reaches_the_same_place_as_the_helpers(): void
    {
        [, $upstream] = $this->echoServer();
        $proxy = $this->toxiproxy()->proxy('generic', $upstream);

        $toxic = $proxy->addToxic(
            type: 'latency',
            stream: 'downstream',
            toxicity: 1.0,
            attributes: ['latency' => 1000, 'jitter' => 100],
        );

        self::assertSame(1000, $toxic->attribute('latency'));
        self::assertSame(100, $toxic->attribute('jitter'));
        self::assertSame('latency_downstream', $toxic->name);
    }
}
