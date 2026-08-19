<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Toxic;

use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicDirection;
use Mpge\Toxiproxy\Toxic\ToxicType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToxicTest extends TestCase
{
    public function test_it_builds_the_payload_toxiproxy_expects(): void
    {
        $toxic = Toxic::make(ToxicType::Latency, ['latency' => 1000, 'jitter' => 100]);

        self::assertSame([
            'name' => 'latency_downstream',
            'type' => 'latency',
            'stream' => 'downstream',
            'toxicity' => 1.0,
            'attributes' => ['latency' => 1000, 'jitter' => 100],
        ], $toxic->toPayload());
    }

    public function test_the_default_name_matches_the_one_the_server_would_generate(): void
    {
        self::assertSame('latency_downstream', Toxic::make(ToxicType::Latency)->name);
        self::assertSame(
            'bandwidth_upstream',
            Toxic::make(ToxicType::Bandwidth, stream: ToxicDirection::Upstream)->name,
        );
    }

    public function test_omitted_attributes_default_to_the_go_zero_value(): void
    {
        $toxic = Toxic::make(ToxicType::Latency, ['latency' => 500]);

        self::assertSame(['latency' => 500, 'jitter' => 0], $toxic->attributes);
    }

    /**
     * Toxiproxy decodes attributes into a typed Go struct, so an unrecognised
     * key is dropped without complaint and the toxic silently does nothing.
     * Catching it here turns a baffling no-op into an obvious failure.
     */
    public function test_it_rejects_attribute_keys_toxiproxy_would_silently_ignore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no attribute "latencyMs"');

        Toxic::make(ToxicType::Latency, ['latencyMs' => 1000]);
    }

    public function test_the_rejection_lists_the_attributes_that_do_exist(): void
    {
        try {
            Toxic::make(ToxicType::Slicer, ['size' => 100]);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('average_size, size_variation, delay', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{ToxicType, list<string>}>
     */
    public static function attributeNames(): iterable
    {
        yield 'latency' => [ToxicType::Latency, ['latency', 'jitter']];
        yield 'bandwidth' => [ToxicType::Bandwidth, ['rate']];
        yield 'slow_close' => [ToxicType::SlowClose, ['delay']];
        yield 'timeout' => [ToxicType::Timeout, ['timeout']];
        yield 'reset_peer' => [ToxicType::ResetPeer, ['timeout']];
        yield 'slicer' => [ToxicType::Slicer, ['average_size', 'size_variation', 'delay']];
        yield 'limit_data' => [ToxicType::LimitData, ['bytes']];
        yield 'noop' => [ToxicType::Noop, []];
    }

    /**
     * These names are copied from the json tags on upstream's toxic structs. If
     * this test ever fails, upstream changed and the package must follow.
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('attributeNames')]
    public function test_attribute_names_mirror_the_upstream_go_structs(ToxicType $type, array $expected): void
    {
        self::assertSame($expected, $type->attributeNames());
    }

    public function test_every_registered_upstream_toxic_type_is_present(): void
    {
        self::assertSame(
            ['latency', 'bandwidth', 'slow_close', 'timeout', 'reset_peer', 'slicer', 'limit_data', 'noop'],
            ToxicType::names(),
        );
    }

    public function test_an_empty_attribute_set_serialises_as_an_object_not_an_array(): void
    {
        // Go's decoder rejects a JSON array where it expects a struct, so a
        // toxic with no attributes has to send {} rather than [].
        $encoded = json_encode(Toxic::make(ToxicType::Noop));

        self::assertIsString($encoded);
        self::assertStringContainsString('"attributes":{}', $encoded);
    }

    public function test_it_hydrates_from_an_api_payload(): void
    {
        $toxic = Toxic::fromArray([
            'name' => 'my_latency',
            'type' => 'latency',
            'stream' => 'upstream',
            'toxicity' => 0.5,
            'attributes' => ['latency' => 250, 'jitter' => 50],
        ]);

        self::assertSame('my_latency', $toxic->name);
        self::assertSame(ToxicType::Latency, $toxic->type);
        self::assertSame(ToxicDirection::Upstream, $toxic->stream);
        self::assertSame(0.5, $toxic->toxicity);
        self::assertSame(250, $toxic->attribute('latency'));
    }

    public function test_a_payload_without_a_stream_defaults_to_downstream_like_the_server_does(): void
    {
        $toxic = Toxic::fromArray(['name' => 'x', 'type' => 'latency', 'attributes' => []]);

        self::assertSame(ToxicDirection::Downstream, $toxic->stream);
        self::assertSame(1.0, $toxic->toxicity);
    }

    public function test_a_payload_without_a_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing a "type"');

        Toxic::fromArray(['name' => 'x']);
    }

    public function test_an_unknown_toxic_type_is_rejected_with_the_valid_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown toxic type "packet_loss"');

        Toxic::fromArray(['type' => 'packet_loss']);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidToxicities(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'above one' => [1.1];
        yield 'percent by mistake' => [25.0];
    }

    #[DataProvider('invalidToxicities')]
    public function test_toxicity_outside_zero_to_one_is_rejected(float $toxicity): void
    {
        $this->expectException(InvalidArgumentException::class);

        Toxic::make(ToxicType::Latency, toxicity: $toxicity);
    }

    public function test_the_boundaries_of_toxicity_are_allowed(): void
    {
        self::assertSame(0.0, Toxic::make(ToxicType::Latency, toxicity: 0.0)->toxicity);
        self::assertSame(1.0, Toxic::make(ToxicType::Latency, toxicity: 1.0)->toxicity);
    }

    public function test_withers_return_new_instances(): void
    {
        $original = Toxic::make(ToxicType::Latency, ['latency' => 100]);

        $changed = $original
            ->withAttributes(['latency' => 200])
            ->withToxicity(0.5)
            ->withName('renamed')
            ->withStream(ToxicDirection::Upstream);

        self::assertSame(100, $original->attribute('latency'));
        self::assertSame(1.0, $original->toxicity);
        self::assertSame('latency_downstream', $original->name);

        self::assertSame(200, $changed->attribute('latency'));
        self::assertSame(0.5, $changed->toxicity);
        self::assertSame('renamed', $changed->name);
        self::assertSame(ToxicDirection::Upstream, $changed->stream);
    }

    public function test_partial_attribute_updates_keep_the_untouched_ones(): void
    {
        $toxic = Toxic::make(ToxicType::Latency, ['latency' => 100, 'jitter' => 50])
            ->withAttributes(['jitter' => 10]);

        self::assertSame(['latency' => 100, 'jitter' => 10], $toxic->attributes);
    }

    public function test_describe_reads_like_something_a_human_wrote(): void
    {
        self::assertSame(
            'latency_downstream (latency: 1000ms, jitter: 100ms)',
            Toxic::make(ToxicType::Latency, ['latency' => 1000, 'jitter' => 100])->describe(),
        );

        self::assertSame(
            'bandwidth_downstream (rate: 50KB/s)',
            Toxic::make(ToxicType::Bandwidth, ['rate' => 50])->describe(),
        );

        self::assertSame(
            'reset_peer_downstream (timeout: 0ms) @ 25% of connections',
            Toxic::make(ToxicType::ResetPeer, ['timeout' => 0], toxicity: 0.25)->describe(),
        );

        self::assertSame('noop_downstream (no attributes)', Toxic::make(ToxicType::Noop)->describe());
    }

    public function test_slicer_delay_is_documented_in_microseconds(): void
    {
        // Upstream's slicer measures delay in microseconds while every other
        // toxic uses milliseconds, so the unit has to survive into output.
        self::assertSame(
            ['average_size' => 'bytes', 'size_variation' => 'bytes', 'delay' => 'us'],
            ToxicType::Slicer->attributeUnits(),
        );
    }

    public function test_directions_describe_the_flow_they_affect(): void
    {
        self::assertSame('service -> client', ToxicDirection::Downstream->describe());
        self::assertSame('client -> service', ToxicDirection::Upstream->describe());
        self::assertSame(ToxicDirection::Upstream, ToxicDirection::Downstream->opposite());
    }

    public function test_an_unknown_stream_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only "upstream" or "downstream"');

        ToxicDirection::fromString('sideways');
    }
}
