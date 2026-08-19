<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use Mpge\Toxiproxy\Toxic\ToxicCollection;
use Mpge\Toxiproxy\Toxic\ToxicDirection;

/**
 * A proxy with one traffic direction pinned.
 *
 * Returned by Proxy::upstream() and Proxy::downstream(); it carries the whole
 * toxic vocabulary but applies everything to the chosen stream.
 *
 *     $proxy->upstream()->bandwidth(50);
 *     $proxy->downstream()->withLatency(1000, fn () => $service->call());
 *
 * Holds no state of its own, so creating one is free and it never goes stale.
 */
final readonly class DirectionalProxy
{
    use AppliesToxics;

    public function __construct(
        private Proxy $proxy,
        private ToxicDirection $direction,
    ) {
    }

    public function proxy(): Proxy
    {
        return $this->proxy;
    }

    public function direction(): ToxicDirection
    {
        return $this->direction;
    }

    /**
     * The toxics on this proxy that affect this direction.
     */
    public function toxics(): ToxicCollection
    {
        return $this->proxy->toxics()->onStream($this->direction);
    }

    /**
     * Flip to the other direction without going back through the proxy.
     */
    public function reverse(): self
    {
        return new self($this->proxy, $this->direction->opposite());
    }

    protected function toxicTarget(): Proxy
    {
        return $this->proxy;
    }

    protected function toxicStream(): ToxicDirection
    {
        return $this->direction;
    }
}
