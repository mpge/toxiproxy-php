<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicDirection;
use Mpge\Toxiproxy\Toxic\ToxicType;

/**
 * The toxic vocabulary, shared by Proxy and DirectionalProxy.
 *
 * Proxy applies these downstream by default, matching Toxiproxy's own default.
 * DirectionalProxy pins them to whichever stream you selected:
 *
 *     $proxy->latency(1000);                 // downstream
 *     $proxy->upstream()->bandwidth(50);     // upstream
 *
 * Every helper here is a thin, named wrapper over addToxic(). Nothing is
 * invented: each maps onto a toxic type the Go server actually registers, with
 * the attribute names it actually reads.
 */
trait AppliesToxics
{
    abstract protected function toxicTarget(): Proxy;

    abstract protected function toxicStream(): ToxicDirection;

    /**
     * The escape hatch. Anything the named helpers do not cover, including
     * toxic types added to a newer Toxiproxy than this package knows about.
     *
     * @param  array<string, int|float>  $attributes
     */
    public function addToxic(
        ToxicType|string $type,
        array $attributes = [],
        ToxicDirection|string|null $stream = null,
        float $toxicity = 1.0,
        ?string $name = null,
    ): Toxic {
        $resolvedType = $type instanceof ToxicType ? $type : ToxicType::fromString($type);
        $resolvedStream = match (true) {
            $stream instanceof ToxicDirection => $stream,
            is_string($stream) => ToxicDirection::fromString($stream),
            default => $this->toxicStream(),
        };

        return $this->toxicTarget()->applyToxic(
            Toxic::make($resolvedType, $attributes, $resolvedStream, $toxicity, $name),
        );
    }

    /**
     * Delay every packet by $latency milliseconds, varying by +/- $jitter.
     */
    public function latency(int $latency, int $jitter = 0, float $toxicity = 1.0, ?string $name = null): Toxic
    {
        return $this->addToxic(
            ToxicType::Latency,
            ['latency' => $latency, 'jitter' => $jitter],
            toxicity: $toxicity,
            name: $name,
        );
    }

    /**
     * Throttle the connection to $rate kilobytes per second.
     *
     * A rate of 0 stops data entirely, which is upstream's behaviour and worth
     * knowing before you use it as "unlimited".
     */
    public function bandwidth(int $rate, float $toxicity = 1.0, ?string $name = null): Toxic
    {
        return $this->addToxic(ToxicType::Bandwidth, ['rate' => $rate], toxicity: $toxicity, name: $name);
    }

    /**
     * Stop all data for $timeout milliseconds, then close the connection.
     *
     * Upstream treats 0 as "never close", so the connection simply hangs. That
     * is the more useful default for testing client-side timeouts.
     */
    public function timeout(int $timeout = 0, float $toxicity = 1.0, ?string $name = null): Toxic
    {
        return $this->addToxic(ToxicType::Timeout, ['timeout' => $timeout], toxicity: $toxicity, name: $name);
    }

    /**
     * Delay the TCP close by $delay milliseconds.
     */
    public function slowClose(int $delay, float $toxicity = 1.0, ?string $name = null): Toxic
    {
        return $this->addToxic(ToxicType::SlowClose, ['delay' => $delay], toxicity: $toxicity, name: $name);
    }

    /**
     * Close the connection with a TCP RST after $timeout milliseconds.
     *
     * With the default 0 the reset happens immediately, which is what an
     * abruptly rebooted server looks like to your client.
     */
    public function resetPeer(int $timeout = 0, float $toxicity = 1.0, ?string $name = null): Toxic
    {
        return $this->addToxic(ToxicType::ResetPeer, ['timeout' => $timeout], toxicity: $toxicity, name: $name);
    }

    /**
     * Chop the stream into roughly $averageSize byte pieces.
     *
     * $delay is in microseconds, not milliseconds. That is upstream's unit and
     * this package does not silently convert it.
     */
    public function slicer(
        int $averageSize,
        int $sizeVariation = 0,
        int $delayMicroseconds = 0,
        float $toxicity = 1.0,
        ?string $name = null,
    ): Toxic {
        return $this->addToxic(
            ToxicType::Slicer,
            ['average_size' => $averageSize, 'size_variation' => $sizeVariation, 'delay' => $delayMicroseconds],
            toxicity: $toxicity,
            name: $name,
        );
    }

    /**
     * Close the connection once $bytes have been transferred.
     */
    public function limitData(int $bytes, float $toxicity = 1.0, ?string $name = null): Toxic
    {
        return $this->addToxic(ToxicType::LimitData, ['bytes' => $bytes], toxicity: $toxicity, name: $name);
    }

    /**
     * Reset a $probability share of connections, where 1.0 is all of them.
     *
     * Toxiproxy has no packet-level loss toxic, and this package will not
     * pretend otherwise. What it does have is `toxicity`: the fraction of
     * *connections* a toxic is applied to. So this arms an immediate TCP reset
     * at that toxicity, which models a lossy or flapping link at the level
     * Toxiproxy actually operates on.
     *
     * If you want dropped bytes mid-stream rather than dropped connections,
     * reach for bandwidth() or limitData() instead.
     */
    public function packetLoss(float $probability, ?string $name = null): Toxic
    {
        return $this->resetPeer(timeout: 0, toxicity: $probability, name: $name);
    }

    /**
     * A toxic that changes nothing. Useful as a placeholder you later update,
     * and as a way to prove your harness is wired up before making it hurt.
     */
    public function noop(?string $name = null): Toxic
    {
        return $this->addToxic(ToxicType::Noop, name: $name);
    }

    // ------------------------------------------------------------ scoped

    /**
     * Run $callback with $toxics applied, then put the proxy back exactly as it
     * was, whether the callback returns or throws.
     *
     * "As it was" means the full toxic set is restored: toxics the callback
     * added are removed, ones it changed are put back, ones it deleted return.
     *
     * @template TReturn
     *
     * @param  iterable<Toxic>  $toxics
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withToxics(iterable $toxics, callable $callback): mixed
    {
        $proxy = $this->toxicTarget();
        $snapshot = $proxy->snapshot();

        try {
            foreach ($toxics as $toxic) {
                $proxy->applyToxic($toxic);
            }

            return $callback();
        } finally {
            $proxy->restore($snapshot);
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withLatency(int $latency, callable $callback, int $jitter = 0, float $toxicity = 1.0): mixed
    {
        return $this->withToxics(
            [$this->buildToxic(ToxicType::Latency, ['latency' => $latency, 'jitter' => $jitter], $toxicity)],
            $callback,
        );
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withBandwidth(int $rate, callable $callback, float $toxicity = 1.0): mixed
    {
        return $this->withToxics([$this->buildToxic(ToxicType::Bandwidth, ['rate' => $rate], $toxicity)], $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withTimeout(int $timeout, callable $callback, float $toxicity = 1.0): mixed
    {
        return $this->withToxics([$this->buildToxic(ToxicType::Timeout, ['timeout' => $timeout], $toxicity)], $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withSlowClose(int $delay, callable $callback, float $toxicity = 1.0): mixed
    {
        return $this->withToxics([$this->buildToxic(ToxicType::SlowClose, ['delay' => $delay], $toxicity)], $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withLimitData(int $bytes, callable $callback, float $toxicity = 1.0): mixed
    {
        return $this->withToxics([$this->buildToxic(ToxicType::LimitData, ['bytes' => $bytes], $toxicity)], $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withSlicer(
        int $averageSize,
        callable $callback,
        int $sizeVariation = 0,
        int $delayMicroseconds = 0,
        float $toxicity = 1.0,
    ): mixed {
        return $this->withToxics([$this->buildToxic(
            ToxicType::Slicer,
            ['average_size' => $averageSize, 'size_variation' => $sizeVariation, 'delay' => $delayMicroseconds],
            $toxicity,
        )], $callback);
    }

    /**
     * See packetLoss() for what "loss" means here: reset connections, not
     * dropped packets.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withPacketLoss(float $probability, callable $callback): mixed
    {
        return $this->withToxics([$this->buildToxic(ToxicType::ResetPeer, ['timeout' => 0], $probability)], $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withResetPeer(int $timeout, callable $callback, float $toxicity = 1.0): mixed
    {
        return $this->withToxics([$this->buildToxic(ToxicType::ResetPeer, ['timeout' => $timeout], $toxicity)], $callback);
    }

    /**
     * @param  array<string, int|float>  $attributes
     */
    private function buildToxic(ToxicType $type, array $attributes, float $toxicity): Toxic
    {
        return Toxic::make($type, $attributes, $this->toxicStream(), $toxicity);
    }
}
