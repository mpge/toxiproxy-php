<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Toxic;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * An immutable, name-keyed set of toxics.
 *
 * @implements IteratorAggregate<string, Toxic>
 */
final readonly class ToxicCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<string, Toxic> */
    private array $toxics;

    /**
     * @param  iterable<Toxic>  $toxics
     */
    public function __construct(iterable $toxics = [])
    {
        $keyed = [];

        foreach ($toxics as $toxic) {
            $keyed[$toxic->name] = $toxic;
        }

        $this->toxics = $keyed;
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(array_map(Toxic::fromArray(...), $payload));
    }

    public function get(string $name): ?Toxic
    {
        return $this->toxics[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->toxics[$name]);
    }

    public function ofType(ToxicType $type): self
    {
        return $this->filter(static fn (Toxic $toxic): bool => $toxic->type === $type);
    }

    public function onStream(ToxicDirection $stream): self
    {
        return $this->filter(static fn (Toxic $toxic): bool => $toxic->stream === $stream);
    }

    /**
     * @param  callable(Toxic): bool  $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_filter($this->toxics, $predicate));
    }

    public function first(): ?Toxic
    {
        foreach ($this->toxics as $toxic) {
            return $toxic;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->toxics);
    }

    /**
     * @return list<Toxic>
     */
    public function all(): array
    {
        return array_values($this->toxics);
    }

    public function isEmpty(): bool
    {
        return $this->toxics === [];
    }

    public function count(): int
    {
        return count($this->toxics);
    }

    /**
     * @return Traversable<string, Toxic>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toxics);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return array_map(static fn (Toxic $toxic): array => $toxic->toArray(), array_values($this->toxics));
    }
}
