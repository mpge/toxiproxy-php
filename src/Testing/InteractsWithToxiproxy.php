<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Testing;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Proxy\Proxy;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxiproxy;
use PHPUnit\Framework\Attributes\After;

/**
 * Toxiproxy in a PHPUnit test case.
 *
 *     final class CheckoutTest extends TestCase
 *     {
 *         use InteractsWithToxiproxy;
 *
 *         public function test_it_survives_a_slow_redis(): void
 *         {
 *             $this->proxy('redis', '127.0.0.1:6379');
 *
 *             $this->withLatency('redis', 2000, function () {
 *                 $this->assertTrue($this->checkout()->succeeded());
 *             });
 *         }
 *     }
 *
 * The server starts once per test process and is shared. Between tests every
 * toxic is removed and every proxy re-enabled, so a test that leaves a service
 * broken cannot poison the next one. Proxy definitions survive, which keeps the
 * ports stable across a whole test class.
 *
 * Works in Pest unchanged: Pest test cases are PHPUnit test cases, so
 * `uses(InteractsWithToxiproxy::class)` in Pest.php is all it takes.
 */
trait InteractsWithToxiproxy
{
    /**
     * Override in your test case to point at a different server, pin a version,
     * or turn off auto-install.
     */
    protected function toxiproxyConfiguration(): Configuration
    {
        return Configuration::fromEnvironment();
    }

    /**
     * The shared Toxiproxy for this test process.
     */
    protected function toxiproxy(): Toxiproxy
    {
        return ToxiproxyManager::instance($this->toxiproxyConfiguration());
    }

    /**
     * Get or create a proxy.
     *
     * Pass an upstream the first time; afterwards the name alone is enough,
     * which keeps the noise down in tests that reference the same proxy a lot.
     */
    protected function proxy(string $name, ?string $upstream = null, ?string $listen = null): Proxy
    {
        if ($upstream !== null) {
            return $this->toxiproxy()->proxy($name, $upstream, $listen);
        }

        return $this->toxiproxy()->client()->proxy($name);
    }

    /**
     * Declare several proxies at once, usually in setUp().
     *
     * @param  array<string, string>  $upstreams  proxy name => upstream address
     * @return array<string, Proxy>
     */
    protected function proxies(array $upstreams): array
    {
        $proxies = [];

        foreach ($upstreams as $name => $upstream) {
            $proxies[$name] = $this->proxy($name, $upstream);
        }

        return $proxies;
    }

    /**
     * Run the callback with the named service slowed down, then restore it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withLatency(string $proxy, int $milliseconds, callable $callback, int $jitter = 0): mixed
    {
        return $this->proxy($proxy)->withLatency($milliseconds, $callback, $jitter);
    }

    /**
     * Run the callback with the named service completely unreachable.
     *
     * This is a severed connection, not a slow one: exactly what your client
     * sees when the service is down.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withServiceDown(string $proxy, callable $callback): mixed
    {
        return $this->proxy($proxy)->down($callback);
    }

    /**
     * @template TReturn
     *
     * @param  int  $kilobytesPerSecond  0 stops data entirely
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withBandwidth(string $proxy, int $kilobytesPerSecond, callable $callback): mixed
    {
        return $this->proxy($proxy)->withBandwidth($kilobytesPerSecond, $callback);
    }

    /**
     * Run the callback with the connection hanging: data stops and, after
     * $milliseconds, the connection closes. Zero means it never closes, which
     * is the harsher and usually more interesting case.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withTimeout(string $proxy, int $milliseconds, callable $callback): mixed
    {
        return $this->proxy($proxy)->withTimeout($milliseconds, $callback);
    }

    /**
     * Reset a share of connections, where 1.0 is all of them.
     *
     * See Proxy::packetLoss(): Toxiproxy works at connection granularity, so
     * this drops connections rather than individual packets.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withPacketLoss(string $proxy, float $probability, callable $callback): mixed
    {
        return $this->proxy($proxy)->withPacketLoss($probability, $callback);
    }

    /**
     * Run the callback with arbitrary toxics applied.
     *
     * @template TReturn
     *
     * @param  iterable<Toxic>  $toxics
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withToxics(string $proxy, iterable $toxics, callable $callback): mixed
    {
        return $this->proxy($proxy)->withToxics($toxics, $callback);
    }

    /**
     * Undo everything the test did to the network: every proxy enabled, every
     * toxic gone.
     *
     * Registered with PHPUnit's #[After] attribute, so it runs automatically
     * after each test without your test case having to remember a tearDown.
     */
    #[After]
    protected function resetToxiproxy(): void
    {
        if (ToxiproxyManager::has($this->toxiproxyConfiguration())) {
            $this->toxiproxy()->reset();
        }
    }
}
