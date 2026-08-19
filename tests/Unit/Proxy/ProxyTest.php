<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Proxy;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Proxy\Proxy;
use Mpge\Toxiproxy\Tests\Support\FakeTransport;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicDirection;
use Mpge\Toxiproxy\Toxic\ToxicType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProxyTest extends TestCase
{
    private FakeTransport $transport;

    private ToxiproxyClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new ToxiproxyClient('http://127.0.0.1:8474', $this->transport);
    }

    public function test_it_exposes_the_address_your_client_should_connect_to(): void
    {
        $proxy = $this->proxy();

        self::assertSame('redis', $proxy->name());
        self::assertSame('127.0.0.1:16379', $proxy->listen());
        self::assertSame('127.0.0.1', $proxy->host());
        self::assertSame(16379, $proxy->port());
        self::assertSame('127.0.0.1:6379', $proxy->upstreamAddress());
        self::assertSame('tcp://127.0.0.1:16379', $proxy->url());
        self::assertTrue($proxy->isEnabled());
    }

    public function test_toxic_helpers_default_to_downstream(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]));

        $this->proxy()->latency(1000);

        self::assertSame('downstream', $this->transport->lastBody()['stream']);
    }

    public function test_the_upstream_handle_pins_the_stream(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, $this->toxicPayload('bandwidth_upstream', 'bandwidth', 'upstream', ['rate' => 50]));

        $this->proxy()->upstream()->bandwidth(50);

        $body = $this->transport->lastBody();

        self::assertSame('upstream', $body['stream']);
        self::assertSame('bandwidth_upstream', $body['name']);
    }

    /**
     * Toxiproxy has no packet_loss toxic. What it has is toxicity, the fraction
     * of connections a toxic applies to, so loss is modelled as reset_peer at
     * that fraction rather than being invented.
     */
    public function test_packet_loss_is_a_reset_peer_at_the_given_toxicity(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, $this->toxicPayload('reset_peer_downstream', 'reset_peer', 'downstream', ['timeout' => 0], 0.25));

        $this->proxy()->packetLoss(0.25);

        $body = $this->transport->lastBody();

        self::assertSame('reset_peer', $body['type']);
        self::assertSame(0.25, $body['toxicity']);
        self::assertSame(['timeout' => 0], $body['attributes']);
    }

    public function test_slicer_passes_its_delay_through_in_microseconds(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, $this->toxicPayload('slicer_downstream', 'slicer', 'downstream', ['average_size' => 64, 'size_variation' => 8, 'delay' => 100]));

        $this->proxy()->slicer(averageSize: 64, sizeVariation: 8, delayMicroseconds: 100);

        self::assertSame(
            ['average_size' => 64, 'size_variation' => 8, 'delay' => 100],
            $this->transport->lastBody()['attributes'],
        );
    }

    /**
     * Toxiproxy rejects a create for a name that is taken. Turning the second
     * call into an update is what makes a test file safe to run twice.
     */
    public function test_applying_the_same_toxic_twice_updates_instead_of_failing(): void
    {
        $payload = $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]);

        $this->transport
            ->on('POST', '/proxies/redis/toxics', 200, $payload)
            ->on('PATCH', '/proxies/redis/toxics/latency_downstream', 200, $payload);

        $proxy = $this->proxy();
        $proxy->latency(1000);
        $proxy->latency(2000);

        self::assertSame(
            ['POST /proxies/redis/toxics', 'PATCH /proxies/redis/toxics/latency_downstream'],
            $this->transport->trace(),
        );
    }

    public function test_scoped_helpers_remove_what_they_added(): void
    {
        $this->armScopedLatency();

        $proxy = $this->proxy();

        $result = $proxy->withLatency(1000, static fn (): string => 'callback ran');

        self::assertSame('callback ran', $result);
        self::assertContains('DELETE /proxies/redis/toxics/latency_downstream', $this->transport->trace());
    }

    public function test_scoped_helpers_restore_even_when_the_callback_throws(): void
    {
        $this->armScopedLatency();

        $proxy = $this->proxy();

        try {
            $proxy->withLatency(1000, static function (): void {
                throw new RuntimeException('the test failed');
            });
            self::fail('The exception should have propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('the test failed', $e->getMessage());
        }

        // This is the whole point of the finally block: a failing assertion
        // inside the callback must not leave the network broken for the next test.
        self::assertContains('DELETE /proxies/redis/toxics/latency_downstream', $this->transport->trace());
    }

    public function test_a_toxic_that_existed_before_the_scope_survives_it(): void
    {
        $existing = $this->toxicPayload('bandwidth_downstream', 'bandwidth', 'downstream', ['rate' => 10]);
        $added = $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]);

        $this->transport
            ->on('POST', '/proxies/redis/toxics', 200, $added)
            ->on('GET', '/proxies/redis/toxics', 200, [$existing, $added])
            ->on('DELETE', '/proxies/redis/toxics/latency_downstream', 204);

        $proxy = $this->proxy(toxics: [$existing]);
        $proxy->withLatency(1000, static fn (): null => null);

        $trace = $this->transport->trace();

        self::assertContains('DELETE /proxies/redis/toxics/latency_downstream', $trace);
        self::assertNotContains('DELETE /proxies/redis/toxics/bandwidth_downstream', $trace);
        self::assertTrue($proxy->toxics()->has('bandwidth_downstream'));
    }

    public function test_down_disables_the_proxy_and_re_enables_it_afterwards(): void
    {
        $this->transport
            ->on('PATCH', '/proxies/redis', 200, $this->proxyPayload(enabled: false))
            ->once('PATCH', '/proxies/redis', 200, $this->proxyPayload(enabled: false));

        $proxy = $this->proxy();

        $proxy->down(static fn (): null => null);

        $bodies = array_map(
            static fn (array $request): ?string => $request['body'],
            array_values(array_filter(
                $this->transport->requests,
                static fn (array $r): bool => $r['method'] === 'PATCH' && $r['path'] === '/proxies/redis',
            )),
        );

        self::assertCount(2, $bodies);
        self::assertSame('{"enabled":false}', $bodies[0]);
        self::assertSame('{"enabled":true}', $bodies[1]);
    }

    public function test_down_re_enables_even_when_the_callback_throws(): void
    {
        $this->transport->on('PATCH', '/proxies/redis', 200, $this->proxyPayload(enabled: false));

        $proxy = $this->proxy();

        try {
            $proxy->down(static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('The exception should have propagated.');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(2, $this->transport->countRequests('PATCH', '/proxies/redis'));
    }

    /**
     * A proxy that was already disabled should stay disabled: down() restores
     * the previous state, it does not force the service back up.
     */
    public function test_down_leaves_an_already_disabled_proxy_disabled(): void
    {
        $this->transport->on('PATCH', '/proxies/redis', 200, $this->proxyPayload(enabled: false));

        $this->proxy(enabled: false)->down(static fn (): null => null);

        self::assertSame(1, $this->transport->countRequests('PATCH', '/proxies/redis'));
        self::assertSame('{"enabled":false}', $this->transport->lastRequest()['body']);
    }

    public function test_remove_toxics_deletes_every_one(): void
    {
        $this->transport
            ->on('DELETE', '/proxies/redis/toxics/latency_downstream', 204)
            ->on('DELETE', '/proxies/redis/toxics/bandwidth_upstream', 204);

        $proxy = $this->proxy(toxics: [
            $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1, 'jitter' => 0]),
            $this->toxicPayload('bandwidth_upstream', 'bandwidth', 'upstream', ['rate' => 1]),
        ]);

        $proxy->removeToxics();

        self::assertTrue($proxy->toxics()->isEmpty());
        self::assertSame(2, $this->transport->countRequests('DELETE'));
    }

    public function test_toxics_can_be_filtered_by_stream(): void
    {
        $proxy = $this->proxy(toxics: [
            $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1, 'jitter' => 0]),
            $this->toxicPayload('bandwidth_upstream', 'bandwidth', 'upstream', ['rate' => 1]),
        ]);

        self::assertCount(2, $proxy->toxics());
        self::assertSame(['latency_downstream'], $proxy->downstream()->toxics()->names());
        self::assertSame(['bandwidth_upstream'], $proxy->upstream()->toxics()->names());
    }

    public function test_a_snapshot_captures_enabled_state_and_toxics(): void
    {
        $proxy = $this->proxy(toxics: [
            $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1, 'jitter' => 0]),
        ]);

        $snapshot = $proxy->snapshot();

        self::assertTrue($snapshot->enabled);
        self::assertSame(['latency_downstream'], $snapshot->toxics->names());
    }

    /**
     * Restoring must not fire an update for a toxic that already matches, or
     * every scoped helper would cost an extra request per surviving toxic.
     */
    public function test_restore_does_nothing_when_the_state_already_matches(): void
    {
        $toxic = $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]);

        $this->transport->on('GET', '/proxies/redis/toxics', 200, [$toxic]);

        $proxy = $this->proxy(toxics: [$toxic]);
        $proxy->restore($proxy->snapshot());

        self::assertSame(['GET /proxies/redis/toxics'], $this->transport->trace());
    }

    public function test_restore_recreates_a_toxic_the_callback_deleted(): void
    {
        $toxic = $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]);

        $this->transport
            ->on('GET', '/proxies/redis/toxics', 200, [])
            ->on('POST', '/proxies/redis/toxics', 200, $toxic);

        $proxy = $this->proxy(toxics: [$toxic]);
        $proxy->restore($proxy->snapshot());

        self::assertContains('POST /proxies/redis/toxics', $this->transport->trace());
    }

    public function test_the_proxy_serialises_back_to_the_api_shape(): void
    {
        $proxy = $this->proxy(toxics: [
            $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1, 'jitter' => 0]),
        ]);

        self::assertSame([
            'name' => 'redis',
            'listen' => '127.0.0.1:16379',
            'upstream' => '127.0.0.1:6379',
            'enabled' => true,
            'toxics' => [[
                'name' => 'latency_downstream',
                'type' => 'latency',
                'stream' => 'downstream',
                'toxicity' => 1.0,
                'attributes' => ['latency' => 1, 'jitter' => 0],
            ]],
        ], $proxy->toArray());
    }

    public function test_the_generic_escape_hatch_accepts_strings_and_enums_alike(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, $this->toxicPayload('custom', 'latency', 'upstream', ['latency' => 5, 'jitter' => 0], 0.5));

        $this->proxy()->addToxic(
            type: 'latency',
            attributes: ['latency' => 5],
            stream: 'upstream',
            toxicity: 0.5,
            name: 'custom',
        );

        self::assertSame([
            'name' => 'custom',
            'type' => 'latency',
            'stream' => 'upstream',
            'toxicity' => 0.5,
            'attributes' => ['latency' => 5, 'jitter' => 0],
        ], $this->transport->lastBody());
    }

    public function test_toxic_enums_work_in_the_escape_hatch_too(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, $this->toxicPayload('limit_data_upstream', 'limit_data', 'upstream', ['bytes' => 1024]));

        $toxic = $this->proxy()->addToxic(ToxicType::LimitData, ['bytes' => 1024], ToxicDirection::Upstream);

        self::assertSame(ToxicType::LimitData, $toxic->type);
        self::assertSame(ToxicDirection::Upstream, $toxic->stream);
    }

    public function test_with_toxics_applies_an_arbitrary_set(): void
    {
        $latency = $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]);
        $bandwidth = $this->toxicPayload('bandwidth_upstream', 'bandwidth', 'upstream', ['rate' => 10]);

        $this->transport
            ->once('POST', '/proxies/redis/toxics', 200, $latency)
            ->once('POST', '/proxies/redis/toxics', 200, $bandwidth)
            ->on('GET', '/proxies/redis/toxics', 200, [$latency, $bandwidth])
            ->on('DELETE', '/proxies/redis/toxics/latency_downstream', 204)
            ->on('DELETE', '/proxies/redis/toxics/bandwidth_upstream', 204);

        $ran = false;

        $this->proxy()->withToxics([
            Toxic::make(ToxicType::Latency, ['latency' => 1000]),
            Toxic::make(ToxicType::Bandwidth, ['rate' => 10], ToxicDirection::Upstream),
        ], static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
        self::assertSame(2, $this->transport->countRequests('DELETE'));
    }

    // -------------------------------------------------------------- helpers

    private function armScopedLatency(): void
    {
        $toxic = $this->toxicPayload('latency_downstream', 'latency', 'downstream', ['latency' => 1000, 'jitter' => 0]);

        $this->transport
            ->on('POST', '/proxies/redis/toxics', 200, $toxic)
            ->on('GET', '/proxies/redis/toxics', 200, [$toxic])
            ->on('DELETE', '/proxies/redis/toxics/latency_downstream', 204);
    }

    /**
     * @param  list<array<string, mixed>>  $toxics
     */
    private function proxy(bool $enabled = true, array $toxics = []): Proxy
    {
        return Proxy::fromArray($this->client, $this->proxyPayload($enabled, $toxics));
    }

    /**
     * @param  list<array<string, mixed>>  $toxics
     * @return array<string, mixed>
     */
    private function proxyPayload(bool $enabled = true, array $toxics = []): array
    {
        return [
            'name' => 'redis',
            'listen' => '127.0.0.1:16379',
            'upstream' => '127.0.0.1:6379',
            'enabled' => $enabled,
            'toxics' => $toxics,
        ];
    }

    /**
     * @param  array<string, int|float>  $attributes
     * @return array<string, mixed>
     */
    private function toxicPayload(string $name, string $type, string $stream, array $attributes, float $toxicity = 1.0): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'stream' => $stream,
            'toxicity' => $toxicity,
            'attributes' => $attributes,
        ];
    }
}
