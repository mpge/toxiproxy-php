<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use JsonSerializable;
use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicCollection;
use Mpge\Toxiproxy\Toxic\ToxicDirection;

/**
 * A live handle on one proxy.
 *
 * The object caches the server's last answer so reading name(), port() and
 * friends is free, and every mutating call refreshes that cache from the
 * response Toxiproxy sends back. Call refresh() if something outside this
 * process may have changed the proxy.
 *
 *     $redis = $toxiproxy->proxy('redis', '127.0.0.1:6379');
 *
 *     $client->connect($redis->host(), $redis->port());
 *
 *     $redis->withLatency(1000, fn () => $service->call());
 */
final class Proxy implements JsonSerializable
{
    use AppliesToxics;

    private function __construct(
        private readonly ToxiproxyClient $client,
        private readonly string $name,
        private string $listen,
        private string $upstream,
        private bool $enabled,
        private ToxicCollection $toxics,
    ) {
    }

    /**
     * @param  array<mixed>  $payload  a proxy object as returned by the API
     */
    public static function fromArray(ToxiproxyClient $client, array $payload): self
    {
        foreach (['name', 'listen', 'upstream'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                throw new InvalidArgumentException(sprintf(
                    'Toxiproxy returned a proxy without a string "%s" field.',
                    $field,
                ));
            }
        }

        /** @var string $name */
        $name = $payload['name'];
        /** @var string $listen */
        $listen = $payload['listen'];
        /** @var string $upstream */
        $upstream = $payload['upstream'];

        $toxics = [];

        if (isset($payload['toxics']) && is_array($payload['toxics'])) {
            foreach ($payload['toxics'] as $entry) {
                if (is_array($entry)) {
                    $toxics[] = Toxic::fromArray($entry);
                }
            }
        }

        return new self($client, $name, $listen, $upstream, (bool) ($payload['enabled'] ?? true), new ToxicCollection($toxics));
    }

    // ------------------------------------------------------------------ state

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The address your client should connect to, as "host:port".
     */
    public function listen(): string
    {
        return $this->listen;
    }

    /**
     * The address of the real service behind the proxy.
     *
     * Named in full because upstream() is taken by the directional toxic
     * helper, which is by far the more common thing to want.
     */
    public function upstreamAddress(): string
    {
        return $this->upstream;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function address(): Address
    {
        return Address::parse($this->listen);
    }

    public function host(): string
    {
        return $this->address()->host;
    }

    public function port(): int
    {
        return $this->address()->port;
    }

    /**
     * A "host:port" string, the same value listen() returns. Provided because
     * `$proxy->address()` reads better in prose but returns an object.
     */
    public function url(string $scheme = 'tcp'): string
    {
        return $scheme.'://'.$this->listen;
    }

    public function client(): ToxiproxyClient
    {
        return $this->client;
    }

    // ---------------------------------------------------------------- toxics

    /**
     * Every toxic on this proxy, both streams.
     */
    public function toxics(): ToxicCollection
    {
        return $this->toxics;
    }

    /**
     * Alias used internally by the shared toxic trait.
     */
    public function allToxics(): ToxicCollection
    {
        return $this->toxics;
    }

    public function toxic(string $name): ?Toxic
    {
        return $this->toxics->get($name);
    }

    /**
     * Pin subsequent toxic helpers to the downstream direction: data flowing
     * from the upstream service back to your client. This is the default.
     */
    public function downstream(): DirectionalProxy
    {
        return new DirectionalProxy($this, ToxicDirection::Downstream);
    }

    /**
     * Pin subsequent toxic helpers to the upstream direction: data flowing from
     * your client towards the service.
     *
     *     $proxy->upstream()->bandwidth(50);
     *
     * For the upstream *address*, see upstreamAddress().
     */
    public function upstream(): DirectionalProxy
    {
        return new DirectionalProxy($this, ToxicDirection::Upstream);
    }

    /**
     * Create or replace a toxic, returning what the server stored.
     *
     * Toxiproxy rejects a create when the name is taken, so an existing toxic
     * of the same name is updated instead. That makes repeated calls in a test
     * idempotent rather than an error.
     */
    public function applyToxic(Toxic $toxic): Toxic
    {
        $existing = $this->toxics->get($toxic->name);

        $stored = $existing !== null && $existing->type === $toxic->type && $existing->stream === $toxic->stream
            ? $this->client->updateToxic($this->name, $toxic)
            : $this->client->createToxic($this->name, $toxic);

        $this->toxics = new ToxicCollection([...$this->toxics->all(), $stored]);

        return $stored;
    }

    public function removeToxic(string $name): self
    {
        $this->client->deleteToxic($this->name, $name);
        $this->toxics = $this->toxics->filter(static fn (Toxic $toxic): bool => $toxic->name !== $name);

        return $this;
    }

    /**
     * Drop every toxic on this proxy, leaving it enabled and clean.
     */
    public function removeToxics(): self
    {
        foreach ($this->toxics as $toxic) {
            $this->client->deleteToxic($this->name, $toxic->name);
        }

        $this->toxics = new ToxicCollection();

        return $this;
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * Re-read this proxy from the server.
     */
    public function refresh(): self
    {
        return $this->adopt($this->client->proxy($this->name));
    }

    public function enable(): self
    {
        return $this->adopt($this->client->enableProxy($this->name));
    }

    /**
     * Stop accepting connections. Existing connections are severed, which is
     * how Toxiproxy models a service being down.
     */
    public function disable(): self
    {
        return $this->adopt($this->client->disableProxy($this->name));
    }

    public function update(?string $listen = null, ?string $upstream = null, ?bool $enabled = null): self
    {
        return $this->adopt($this->client->updateProxy($this->name, $listen, $upstream, $enabled));
    }

    public function delete(): void
    {
        $this->client->deleteProxy($this->name);
        $this->toxics = new ToxicCollection();
        $this->enabled = false;
    }

    /**
     * Run $callback with the service unreachable, then bring it back.
     *
     * The proxy is restored even if the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function down(callable $callback): mixed
    {
        $wasEnabled = $this->enabled;
        $this->disable();

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $this->enable();
            }
        }
    }

    // -------------------------------------------------------------- snapshot

    public function snapshot(): ProxySnapshot
    {
        return new ProxySnapshot($this->enabled, $this->toxics);
    }

    /**
     * Put the proxy back into a previously captured state.
     *
     * Toxics absent from the snapshot are deleted, missing ones recreated, and
     * changed ones updated in place, so the proxy ends up exactly as it was
     * regardless of what happened in between.
     */
    public function restore(ProxySnapshot $snapshot): self
    {
        if ($this->enabled !== $snapshot->enabled) {
            $this->client->updateProxy($this->name, enabled: $snapshot->enabled);
            $this->enabled = $snapshot->enabled;
        }

        $live = $this->client->toxics($this->name);

        foreach ($live as $toxic) {
            if (! $snapshot->toxics->has($toxic->name)) {
                $this->client->deleteToxic($this->name, $toxic->name);
            }
        }

        foreach ($snapshot->toxics as $wanted) {
            $current = $live->get($wanted->name);

            if ($current === null) {
                $this->client->createToxic($this->name, $wanted);

                continue;
            }

            // A toxic's type and stream are immutable server-side, so a change
            // in either can only be honoured by recreating it.
            if ($current->type !== $wanted->type || $current->stream !== $wanted->stream) {
                $this->client->deleteToxic($this->name, $wanted->name);
                $this->client->createToxic($this->name, $wanted);

                continue;
            }

            if ($current->toArray() !== $wanted->toArray()) {
                $this->client->updateToxic($this->name, $wanted);
            }
        }

        $this->toxics = $snapshot->toxics;

        return $this;
    }

    // -------------------------------------------------------- serialisation

    /**
     * @return array{name: string, listen: string, upstream: string, enabled: bool, toxics: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'listen' => $this->listen,
            'upstream' => $this->upstream,
            'enabled' => $this->enabled,
            'toxics' => $this->toxics->jsonSerialize(),
        ];
    }

    /**
     * @return array{name: string, listen: string, upstream: string, enabled: bool, toxics: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function definition(): ProxyDefinition
    {
        return new ProxyDefinition($this->name, $this->listen, $this->upstream, $this->enabled);
    }

    // --------------------------------------------------------------- private

    protected function toxicTarget(): self
    {
        return $this;
    }

    protected function toxicStream(): ToxicDirection
    {
        return ToxicDirection::Downstream;
    }

    /**
     * Copy freshly fetched state onto this handle, so callers holding a
     * reference keep seeing the truth.
     */
    private function adopt(self $fresh): self
    {
        $this->listen = $fresh->listen;
        $this->upstream = $fresh->upstream;
        $this->enabled = $fresh->enabled;
        $this->toxics = $fresh->toxics;

        return $this;
    }
}
