<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Client\Transport;
use Mpge\Toxiproxy\Client\Transports;
use Mpge\Toxiproxy\Proxy\Proxy;
use Mpge\Toxiproxy\Proxy\ProxyCollection;
use Mpge\Toxiproxy\Proxy\ProxyDefinition;
use Mpge\Toxiproxy\Server\Server;

/**
 * The front door.
 *
 *     $toxiproxy = Toxiproxy::start();
 *
 *     $redis = $toxiproxy->proxy('redis', '127.0.0.1:6379');
 *
 *     $redis->withLatency(1000, function () {
 *         // your application, talking to a Redis that has gone slow
 *     });
 *
 *     $toxiproxy->stop();
 *
 * start() installs the official Toxiproxy binary if it is missing, starts it,
 * and hands you a client. If a Toxiproxy is already listening it is reused and
 * left running when you stop, because it is not yours to kill.
 */
final class Toxiproxy
{
    public function __construct(
        private readonly ToxiproxyClient $client,
        private readonly ?Server $server = null,
        private readonly Configuration $config = new Configuration(),
    ) {
    }

    /**
     * Configure before starting.
     *
     *     Toxiproxy::make()->port(9474)->version('2.11.0')->start();
     */
    public static function make(?Configuration $config = null): PendingToxiproxy
    {
        return new PendingToxiproxy($config ?? Configuration::fromEnvironment());
    }

    /**
     * Start, or adopt, a Toxiproxy server and connect to it.
     */
    public static function start(?Configuration $config = null): self
    {
        return self::make($config)->start();
    }

    /**
     * Connect to a server somebody else is responsible for.
     *
     * Nothing is installed, started or stopped. This is the right entry point
     * for CI where Toxiproxy runs as a service container.
     */
    public static function connect(
        string|Configuration|null $endpoint = null,
        ?Transport $transport = null,
    ): self {
        $config = $endpoint instanceof Configuration ? $endpoint : Configuration::fromEnvironment();
        $url = is_string($endpoint) ? $endpoint : $config->apiUrl();

        return new self(new ToxiproxyClient($url, $transport ?? Transports::default()), null, $config);
    }

    /**
     * Run Toxiproxy in a container instead of as a native binary.
     *
     *     Toxiproxy::docker()->start();
     *
     * Read DockerServer's notes first: upstream addresses resolve inside the
     * container, and proxy ports have to be published.
     */
    public static function docker(?Configuration $config = null): PendingToxiproxy
    {
        return self::make($config)->docker();
    }

    // ------------------------------------------------------------------ state

    public function client(): ToxiproxyClient
    {
        return $this->client;
    }

    public function server(): ?Server
    {
        return $this->server;
    }

    public function config(): Configuration
    {
        return $this->config;
    }

    public function endpoint(): string
    {
        return $this->client->baseUrl();
    }

    public function version(): string
    {
        return $this->client->version();
    }

    public function isRunning(): bool
    {
        return $this->client->isRunning();
    }

    /**
     * True when this object started the server, and may therefore stop it.
     */
    public function ownsServer(): bool
    {
        return $this->server?->ownsProcess() ?? false;
    }

    // ---------------------------------------------------------------- proxies

    /**
     * Get or create a proxy.
     *
     * Calling this twice with the same arguments is safe: the second call
     * returns the existing proxy rather than failing with "proxy already
     * exists", which is what you want when a test file runs more than once.
     *
     * Leave $listen out and Toxiproxy picks a free port for you; read it back
     * with $proxy->port().
     */
    public function proxy(
        string $name,
        string $upstream,
        ?string $listen = null,
        bool $enabled = true,
    ): Proxy {
        return $this->client->ensureProxy($name, $upstream, $listen, $enabled, $this->config->proxyHost);
    }

    /**
     * Create a proxy, failing if the name is taken.
     */
    public function createProxy(
        string $name,
        string $upstream,
        ?string $listen = null,
        bool $enabled = true,
    ): Proxy {
        return $this->client->createProxy($name, $upstream, $listen, $enabled, $this->config->proxyHost);
    }

    public function findProxy(string $name): ?Proxy
    {
        return $this->client->findProxy($name);
    }

    public function proxies(): ProxyCollection
    {
        return $this->client->proxies();
    }

    /**
     * Declare a whole set of proxies at once.
     *
     * @param  iterable<ProxyDefinition|array<string, mixed>>  $definitions
     */
    public function populate(iterable $definitions): ProxyCollection
    {
        return $this->client->populate($definitions);
    }

    public function deleteProxy(string $name): self
    {
        $this->client->deleteProxy($name);

        return $this;
    }

    /**
     * Re-enable every proxy and remove every toxic, leaving the proxies in place.
     *
     * The natural thing to call between tests.
     */
    public function reset(): self
    {
        $this->client->reset();

        return $this;
    }

    /**
     * Delete every proxy. Heavier than reset(), and only needed when proxy
     * definitions themselves differ between tests.
     */
    public function flush(): self
    {
        $this->client->deleteAllProxies();

        return $this;
    }

    // -------------------------------------------------------------- lifecycle

    /**
     * Stop the server, if this object started it.
     *
     * Returns false when there is nothing to stop, including the case where the
     * server was already running before we connected. That is not a failure.
     */
    public function stop(float $graceSeconds = 5.0): bool
    {
        return $this->server?->stop($graceSeconds) ?? false;
    }

    /**
     * Whatever the server has logged, or an empty string for a connection to
     * somebody else's server.
     */
    public function logs(): string
    {
        return $this->server?->logs() ?? '';
    }
}
