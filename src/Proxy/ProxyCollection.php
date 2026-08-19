<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Mpge\Toxiproxy\Exception\ProxyNotFoundException;
use Traversable;

/**
 * A name-keyed set of proxies, as returned by GET /proxies.
 *
 * @implements IteratorAggregate<string, Proxy>
 */
final readonly class ProxyCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<string, Proxy> */
    private array $proxies;

    /**
     * @param  iterable<Proxy>  $proxies
     */
    public function __construct(iterable $proxies = [])
    {
        $keyed = [];

        foreach ($proxies as $proxy) {
            $keyed[$proxy->name()] = $proxy;
        }

        ksort($keyed);

        $this->proxies = $keyed;
    }

    /**
     * @throws ProxyNotFoundException
     */
    public function get(string $name): Proxy
    {
        return $this->proxies[$name] ?? throw ProxyNotFoundException::named($name);
    }

    public function find(string $name): ?Proxy
    {
        return $this->proxies[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->proxies[$name]);
    }

    /**
     * @param  callable(Proxy): bool  $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_filter($this->proxies, $predicate));
    }

    public function enabled(): self
    {
        return $this->filter(static fn (Proxy $proxy): bool => $proxy->isEnabled());
    }

    public function disabled(): self
    {
        return $this->filter(static fn (Proxy $proxy): bool => ! $proxy->isEnabled());
    }

    /**
     * Proxies carrying at least one toxic, which is usually the interesting
     * subset when something in a test suite is behaving strangely.
     */
    public function poisoned(): self
    {
        return $this->filter(static fn (Proxy $proxy): bool => ! $proxy->toxics()->isEmpty());
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->proxies);
    }

    /**
     * @return list<Proxy>
     */
    public function all(): array
    {
        return array_values($this->proxies);
    }

    public function first(): ?Proxy
    {
        foreach ($this->proxies as $proxy) {
            return $proxy;
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->proxies === [];
    }

    public function count(): int
    {
        return count($this->proxies);
    }

    /**
     * @return Traversable<string, Proxy>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->proxies);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return array_map(static fn (Proxy $proxy): array => $proxy->toArray(), $this->proxies);
    }
}
