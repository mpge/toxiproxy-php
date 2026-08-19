<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use Mpge\Toxiproxy\Exception\ApiException;
use Mpge\Toxiproxy\Exception\ConnectionException;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Exception\ProxyNotFoundException;
use Mpge\Toxiproxy\Exception\ToxicNotFoundException;
use Mpge\Toxiproxy\Proxy\Address;
use Mpge\Toxiproxy\Proxy\PortAllocator;
use Mpge\Toxiproxy\Proxy\Proxy;
use Mpge\Toxiproxy\Proxy\ProxyCollection;
use Mpge\Toxiproxy\Proxy\ProxyDefinition;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicCollection;

/**
 * A complete client for the Toxiproxy HTTP API.
 *
 * One method per documented endpoint, returning objects rather than arrays.
 * This layer knows nothing about starting servers or downloading binaries; see
 * Mpge\Toxiproxy\Toxiproxy for that.
 *
 *     $client = new ToxiproxyClient('http://127.0.0.1:8474');
 *     $proxy = $client->createProxy(name: 'mysql', upstream: '127.0.0.1:3306', listen: '127.0.0.1:13306');
 */
final class ToxiproxyClient
{
    public const DEFAULT_PORT = 8474;

    private readonly HttpClient $http;

    public function __construct(
        string $baseUrl = 'http://127.0.0.1:8474',
        ?Transport $transport = null,
    ) {
        $this->http = new HttpClient($baseUrl, $transport ?? Transports::default());
    }

    public function baseUrl(): string
    {
        return $this->http->baseUrl();
    }

    // ------------------------------------------------------------------ server

    /**
     * The version string reported by GET /version, for example "2.12.0".
     */
    public function version(): string
    {
        $payload = $this->http->get('/version');

        if (isset($payload['version']) && is_string($payload['version'])) {
            return $payload['version'];
        }

        throw new ApiException('GET /version did not return a version string.', 0, 'GET', '/version');
    }

    /**
     * True when a Toxiproxy API answers on this endpoint.
     *
     * Never throws for an unreachable server, so it is safe to call before
     * deciding whether to spawn one.
     */
    public function isRunning(): bool
    {
        try {
            $this->version();

            return true;
        } catch (ConnectionException | ApiException) {
            return false;
        }
    }

    /**
     * Re-enable every proxy and drop every toxic. Proxies themselves survive.
     */
    public function reset(): void
    {
        $this->http->post('/reset');
    }

    // ----------------------------------------------------------------- proxies

    public function proxies(): ProxyCollection
    {
        $payload = $this->http->get('/proxies');
        $proxies = [];

        foreach ($payload as $entry) {
            if (is_array($entry)) {
                $proxies[] = Proxy::fromArray($this, $entry);
            }
        }

        return new ProxyCollection($proxies);
    }

    /**
     * @throws ProxyNotFoundException
     */
    public function proxy(string $name): Proxy
    {
        return $this->findProxy($name) ?? throw ProxyNotFoundException::named($name);
    }

    public function findProxy(string $name): ?Proxy
    {
        try {
            return Proxy::fromArray($this, $this->http->get($this->proxyPath($name)));
        } catch (ApiException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function hasProxy(string $name): bool
    {
        return $this->findProxy($name) !== null;
    }

    /**
     * Create a proxy.
     *
     * Leave $listen null to have Toxiproxy bind an ephemeral port and report
     * back the one the kernel handed it. That is race-free, unlike choosing a
     * port in PHP and hoping nothing else takes it first.
     *
     * @param  string|null  $listen  "host:port", ":port", a bare port, or null for automatic
     */
    public function createProxy(
        string $name,
        string $upstream,
        ?string $listen = null,
        bool $enabled = true,
        string $listenHost = Address::DEFAULT_HOST,
    ): Proxy {
        $address = $this->resolveListenAddress($listen, $enabled, $listenHost);

        $payload = $this->http->post('/proxies', [
            'name' => $name,
            'listen' => $address->toString(),
            'upstream' => $upstream,
            'enabled' => $enabled,
        ]);

        return Proxy::fromArray($this, $payload);
    }

    /**
     * Create the proxy, or hand back the existing one when it already matches.
     *
     * A proxy whose upstream differs is rebuilt rather than reused, because
     * Toxiproxy would otherwise keep routing to the old address.
     */
    public function ensureProxy(
        string $name,
        string $upstream,
        ?string $listen = null,
        bool $enabled = true,
        string $listenHost = Address::DEFAULT_HOST,
    ): Proxy {
        $existing = $this->findProxy($name);

        if ($existing === null) {
            return $this->createProxy($name, $upstream, $listen, $enabled, $listenHost);
        }

        $requested = $listen === null ? null : Address::parse($listen, $listenHost);
        $listenMatches = $requested === null
            || $requested->isEphemeral()
            || $requested->toString() === $existing->listen();

        if ($existing->upstreamAddress() === $upstream && $listenMatches) {
            return $enabled === $existing->isEnabled()
                ? $existing
                : $this->updateProxy($name, enabled: $enabled);
        }

        $this->deleteProxy($name);

        return $this->createProxy($name, $upstream, $listen, $enabled, $listenHost);
    }

    /**
     * Update a proxy. Omitted fields keep their current value, which mirrors
     * how Toxiproxy defaults the request body server-side.
     */
    public function updateProxy(
        string $name,
        ?string $listen = null,
        ?string $upstream = null,
        ?bool $enabled = null,
    ): Proxy {
        $body = [];

        if ($listen !== null) {
            $body['listen'] = $listen;
        }

        if ($upstream !== null) {
            $body['upstream'] = $upstream;
        }

        if ($enabled !== null) {
            $body['enabled'] = $enabled;
        }

        if ($body === []) {
            throw new InvalidArgumentException('updateProxy() needs at least one of listen, upstream or enabled.');
        }

        $payload = $this->guardProxy($name, fn (): array => $this->http->patch($this->proxyPath($name), $body));

        return Proxy::fromArray($this, $payload);
    }

    public function enableProxy(string $name): Proxy
    {
        return $this->updateProxy($name, enabled: true);
    }

    public function disableProxy(string $name): Proxy
    {
        return $this->updateProxy($name, enabled: false);
    }

    public function deleteProxy(string $name): void
    {
        $this->guardProxy($name, function () use ($name): array {
            $this->http->delete($this->proxyPath($name));

            return [];
        });
    }

    /**
     * Delete every proxy on the server. Useful in test tearDown.
     */
    public function deleteAllProxies(): void
    {
        foreach ($this->proxies() as $proxy) {
            $this->deleteProxy($proxy->name());
        }
    }

    /**
     * Create or replace a whole set of proxies in one request.
     *
     * @param  iterable<ProxyDefinition|array<string, mixed>>  $definitions
     */
    public function populate(iterable $definitions): ProxyCollection
    {
        $body = [];

        foreach ($definitions as $definition) {
            $body[] = ($definition instanceof ProxyDefinition
                ? $definition
                : ProxyDefinition::fromArray($definition))->toPayload();
        }

        if ($body === []) {
            return new ProxyCollection();
        }

        $payload = $this->http->post('/populate', $body);
        $proxies = [];

        if (isset($payload['proxies']) && is_array($payload['proxies'])) {
            foreach ($payload['proxies'] as $entry) {
                if (is_array($entry)) {
                    $proxies[] = Proxy::fromArray($this, $entry);
                }
            }
        }

        return new ProxyCollection($proxies);
    }

    // ------------------------------------------------------------------ toxics

    public function toxics(string $proxy): ToxicCollection
    {
        $payload = $this->guardProxy($proxy, fn (): array => $this->http->get($this->proxyPath($proxy).'/toxics'));

        $toxics = [];

        foreach ($payload as $entry) {
            if (is_array($entry)) {
                $toxics[] = Toxic::fromArray($entry);
            }
        }

        return new ToxicCollection($toxics);
    }

    /**
     * @throws ToxicNotFoundException
     */
    public function toxic(string $proxy, string $name): Toxic
    {
        return $this->findToxic($proxy, $name) ?? throw ToxicNotFoundException::named($proxy, $name);
    }

    public function findToxic(string $proxy, string $name): ?Toxic
    {
        try {
            return Toxic::fromArray($this->http->get($this->toxicPath($proxy, $name)));
        } catch (ApiException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }
    }

    public function createToxic(string $proxy, Toxic $toxic): Toxic
    {
        $payload = $this->guardProxy(
            $proxy,
            fn (): array => $this->http->post($this->proxyPath($proxy).'/toxics', $toxic->toPayload()),
        );

        return Toxic::fromArray($payload);
    }

    /**
     * Update an existing toxic in place.
     *
     * Toxiproxy looks the toxic up by name, and neither its type nor its stream
     * can change, so only toxicity and attributes are sent.
     */
    public function updateToxic(string $proxy, Toxic $toxic): Toxic
    {
        $payload = $this->guardToxic($proxy, $toxic->name, fn (): array => $this->http->patch(
            $this->toxicPath($proxy, $toxic->name),
            [
                'toxicity' => $toxic->toxicity,
                'attributes' => $toxic->toPayload()['attributes'],
            ],
        ));

        return Toxic::fromArray($payload);
    }

    public function deleteToxic(string $proxy, string $name): void
    {
        $this->guardToxic($proxy, $name, function () use ($proxy, $name): array {
            $this->http->delete($this->toxicPath($proxy, $name));

            return [];
        });
    }

    public function deleteAllToxics(string $proxy): void
    {
        foreach ($this->toxics($proxy) as $toxic) {
            $this->deleteToxic($proxy, $toxic->name);
        }
    }

    // ----------------------------------------------------------------- private

    private function proxyPath(string $name): string
    {
        return '/proxies/'.rawurlencode($name);
    }

    private function toxicPath(string $proxy, string $toxic): string
    {
        return $this->proxyPath($proxy).'/toxics/'.rawurlencode($toxic);
    }

    /**
     * Turn Toxiproxy's generic 404 into a typed, actionable exception.
     *
     * @param  callable(): array<mixed>  $operation
     * @return array<mixed>
     */
    private function guardProxy(string $proxy, callable $operation): array
    {
        try {
            return $operation();
        } catch (ApiException $e) {
            throw $e->statusCode === 404 ? ProxyNotFoundException::named($proxy) : $e;
        }
    }

    /**
     * A 404 here means either the proxy or the toxic is missing, and the caller
     * deserves to be told which.
     *
     * @param  callable(): array<mixed>  $operation
     * @return array<mixed>
     */
    private function guardToxic(string $proxy, string $toxic, callable $operation): array
    {
        try {
            return $operation();
        } catch (ApiException $e) {
            if ($e->statusCode !== 404) {
                throw $e;
            }

            throw $this->hasProxy($proxy)
                ? ToxicNotFoundException::named($proxy, $toxic)
                : ProxyNotFoundException::named($proxy);
        }
    }

    private function resolveListenAddress(?string $listen, bool $enabled, string $listenHost): Address
    {
        $address = $listen === null
            ? new Address($listenHost, 0)
            : Address::parse($listen, $listenHost);

        // A disabled proxy never opens its listener, so Toxiproxy would echo
        // back port 0 and the caller would have no port to connect to later.
        if ($address->isEphemeral() && ! $enabled) {
            return $address->withPort(PortAllocator::free($address->host));
        }

        return $address;
    }
}
