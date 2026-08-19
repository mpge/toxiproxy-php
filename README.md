# Toxiproxy for PHP, batteries included

[![CI](https://github.com/mpge/toxiproxy-php/actions/workflows/ci.yml/badge.svg)](https://github.com/mpge/toxiproxy-php/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4)](composer.json)
[![Toxiproxy](https://img.shields.io/badge/toxiproxy-2.12.0-00ADD8)](https://github.com/Shopify/toxiproxy)
[![Licence](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)

Your Redis goes slow. Your MySQL stops answering. Your payment provider resets the
connection halfway through a request. You have no idea what your application does,
because you have never made those things happen on purpose.

[Toxiproxy](https://github.com/Shopify/toxiproxy) is Shopify's answer to that: a TCP
proxy that sits between your application and its dependencies and breaks the network
on demand. It is two pieces — a Go server that does the proxying, and a client library
per language that tells it what to break.

This package is the PHP client, plus the part every other client leaves to you: it
downloads, starts, stops and cleans up the official Go server for you.

```bash
composer require mpge/toxiproxy-php --dev
```

```php
use Mpge\Toxiproxy\Toxiproxy;

$toxiproxy = Toxiproxy::start();

$redis = $toxiproxy->proxy('redis', '127.0.0.1:6379');

$redis->withLatency(1000, function () {
    // verify your application handles a slow Redis connection
});
```

That is the whole setup. No Homebrew, no `docker-compose.yml`, no binary committed to
your repository, no `toxiproxy-server &` in a CI script. The first call downloads the
official release binary for your platform, verifies its checksum, starts it, and stops
it again when your process exits.

---

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Proxies](#proxies)
- [Toxics](#toxics)
- [Scoped chaos](#scoped-chaos)
- [Testing](#testing)
  - [PHPUnit](#phpunit)
  - [Pest](#pest)
- [Laravel](#laravel)
- [Command line](#command-line)
- [Binary management](#binary-management)
- [Using a server you manage yourself](#using-a-server-you-manage-yourself)
- [Docker](#docker)
- [Continuous integration](#continuous-integration)
- [Configuration](#configuration)
- [Supported platforms](#supported-platforms)
- [Testing against third-party APIs](#testing-against-third-party-apis)
- [Troubleshooting](#troubleshooting)
- [How this differs from ihsw/toxiproxy-php-client](#how-this-differs-from-ihswtoxiproxy-php-client)
- [Architecture](#architecture)
- [Licence and attribution](#licence-and-attribution)

---

## Installation

```bash
composer require mpge/toxiproxy-php --dev
```

Requires PHP 8.2 or newer. Dependencies are Symfony Console and Symfony Process, and
that is all — there is deliberately no HTTP client dependency, so installing this into
an existing project cannot conflict with whatever it already uses. See
[Transports](#transports) if you would rather it went through your own.

Optionally pre-download the server binary, so the first test run is not also a download:

```bash
vendor/bin/toxiproxy-php install
```

---

## Quick start

```php
use Mpge\Toxiproxy\Toxiproxy;

// Starts the Go server, or adopts one already running on the same port.
$toxiproxy = Toxiproxy::start();

// No listen address given, so Toxiproxy picks a free port and tells us which.
$redis = $toxiproxy->proxy(
    name: 'redis',
    upstream: '127.0.0.1:6379',
);

// Point your client at the proxy instead of the real service.
$client = new Redis();
$client->connect($redis->host(), $redis->port());

$client->ping();          // fast

$redis->latency(2000);

$client->ping();          // two seconds slower

$redis->removeToxics();

$toxiproxy->stop();
```

`Toxiproxy::stop()` only stops a server this object started. One that was already
running when you connected is left alone — see [ownership](#ownership).

---

## Proxies

A proxy is a listening socket that forwards to an upstream, with toxics applied in
between.

```php
$proxy = $toxiproxy->proxy(
    name: 'mysql',
    upstream: '127.0.0.1:3306',
    listen: '127.0.0.1:13306',   // optional
);
```

`proxy()` is get-or-create: calling it twice with the same arguments returns the same
proxy rather than failing, which is what you want in a test file that runs more than
once. Use `createProxy()` if you want a duplicate name to be an error.

### Automatic ports

Leave `listen` out and Toxiproxy binds an ephemeral port, then reports which one it
got:

```php
$proxy = $toxiproxy->proxy('redis', '127.0.0.1:6379');

$proxy->host();        // '127.0.0.1'
$proxy->port();        // 51824
$proxy->listen();      // '127.0.0.1:51824'
$proxy->address();     // an Address value object
```

This is delegated to the server on purpose. Picking a free port in PHP means binding a
socket, reading the port, closing it, and hoping nothing else claims it in between.
Asking Toxiproxy to bind port 0 has no such window.

### Reading and changing a proxy

```php
$proxy->name();               // 'mysql'
$proxy->upstreamAddress();    // '127.0.0.1:3306'  (upstream() is the toxic helper)
$proxy->isEnabled();          // true
$proxy->toxics();             // a ToxicCollection

$proxy->disable();            // stop accepting connections, sever existing ones
$proxy->enable();
$proxy->update(upstream: '127.0.0.1:3307');
$proxy->refresh();            // re-read from the server
$proxy->delete();
```

### Several at once

```php
use Mpge\Toxiproxy\Proxy\ProxyDefinition;

$toxiproxy->populate([
    new ProxyDefinition('redis', '127.0.0.1:16379', '127.0.0.1:6379'),
    new ProxyDefinition('mysql', '127.0.0.1:13306', '127.0.0.1:3306'),
]);

$toxiproxy->proxies();          // a ProxyCollection, keyed by name
$toxiproxy->proxies()->poisoned();   // only the ones carrying toxics
```

### Cleaning up

```php
$toxiproxy->reset();     // enable every proxy, drop every toxic, keep the proxies
$toxiproxy->flush();     // delete the proxies too
```

`reset()` is the one you want between test cases: it is a single HTTP call and leaves
your proxy ports stable.

---

## Toxics

Every helper maps onto a toxic the Go server actually registers, using the attribute
names it actually reads.

```php
$proxy->latency(1000);                            // delay each packet by 1000ms
$proxy->latency(latency: 1000, jitter: 250);      // 1000ms ± 250ms
$proxy->bandwidth(100);                           // throttle to 100 KB/s
$proxy->timeout(5000);                            // stop data, close after 5s
$proxy->timeout();                                // stop data, never close
$proxy->slowClose(2000);                          // delay the TCP close by 2s
$proxy->resetPeer();                              // TCP RST, immediately
$proxy->limitData(1024);                          // close after 1KB has passed
$proxy->slicer(averageSize: 64, sizeVariation: 16, delayMicroseconds: 100);
$proxy->packetLoss(0.25);                         // see the note below
$proxy->noop();                                   // changes nothing
```

Every helper returns the `Toxic` the server stored, so you can read back what it made
of your request.

### Upstream and downstream

Toxiproxy names directions from your client's point of view. Helpers default to
downstream, matching the server's own default.

```php
$proxy->downstream()->latency(1000);   // service -> client
$proxy->upstream()->bandwidth(50);     // client -> service

$proxy->latency(1000);                 // same as ->downstream()->latency(1000)
```

### The generic API

Anything the named helpers do not cover, including a toxic type added to a newer
Toxiproxy than this package knows about:

```php
$proxy->addToxic(
    type: 'latency',
    stream: 'downstream',
    toxicity: 1.0,
    attributes: [
        'latency' => 1000,
        'jitter' => 100,
    ],
);
```

Attribute keys are validated against the type before the request is sent. This matters
more than it looks: the Go server decodes attributes into a typed struct, so a
misspelled key is dropped without complaint and you get a toxic that is accepted, is
listed, and does nothing at all. Sending `latencyMs` instead of `latency` fails here
rather than wasting an afternoon.

### Toxicity

Every toxic carries a `toxicity` between 0 and 1: the fraction of *connections* it is
applied to.

```php
$proxy->latency(1000, toxicity: 0.5);   // half of connections are slow
```

### A note on `packetLoss()`

**Toxiproxy has no packet-loss toxic.** Its toxics are exactly `latency`, `bandwidth`,
`slow_close`, `timeout`, `reset_peer`, `slicer`, `limit_data` and `noop`.

`packetLoss(0.25)` is therefore a `reset_peer` toxic at toxicity 0.25: one connection
in four is reset. That models a lossy or flapping link at the granularity Toxiproxy
actually works at, which is connections rather than packets. It is documented here
rather than hidden, because a helper that silently means something different from its
name is worse than no helper.

If you want bytes dropped mid-stream instead of connections dropped, use `bandwidth()`
or `limitData()`.

### Managing toxics directly

```php
$proxy->toxic('latency_downstream');       // ?Toxic
$proxy->removeToxic('latency_downstream');
$proxy->removeToxics();                    // all of them

$proxy->toxics()->names();
$proxy->toxics()->ofType(ToxicType::Latency);
$proxy->toxics()->onStream(ToxicDirection::Upstream);
```

Applying a toxic whose name already exists updates it rather than failing, so repeated
calls are safe.

---

## Scoped chaos

The helpers you will actually reach for. Break something, run some code, put it back:

```php
$proxy->withLatency(1000, function () use ($service) {
    $service->call();
});

$proxy->down(function () use ($service) {
    $service->call();   // the service is unreachable in here
});
```

The proxy is restored in a `finally` block, so a failing assertion inside the callback
cannot leak a toxic into the rest of your suite. Restoring means restoring: toxics the
callback added are removed, ones it changed are put back, ones it deleted return.

The callback's return value is passed through:

```php
$duration = $proxy->withLatency(500, fn () => $this->timeCheckout());
```

The full set:

```php
$proxy->withLatency(1000, $callback, jitter: 100);
$proxy->withBandwidth(50, $callback);
$proxy->withTimeout(5000, $callback);
$proxy->withSlowClose(2000, $callback);
$proxy->withLimitData(1024, $callback);
$proxy->withSlicer(64, $callback);
$proxy->withResetPeer(0, $callback);
$proxy->withPacketLoss(0.25, $callback);
$proxy->withToxics([$toxicA, $toxicB], $callback);

$proxy->upstream()->withBandwidth(50, $callback);   // directional
```

---

## Testing

### PHPUnit

```php
use Mpge\Toxiproxy\Testing\InteractsWithToxiproxy;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    use InteractsWithToxiproxy;

    protected function setUp(): void
    {
        $this->proxy('redis', '127.0.0.1:6379');
        $this->proxy('mysql', '127.0.0.1:3306');
    }

    public function test_checkout_survives_a_slow_cache(): void
    {
        $this->withLatency('redis', 2000, function () {
            $this->assertTrue($this->checkout()->succeeded());
        });
    }

    public function test_checkout_fails_cleanly_when_the_database_is_down(): void
    {
        $this->withServiceDown('mysql', function () {
            $this->assertSame('try again shortly', $this->checkout()->message());
        });
    }
}
```

The trait gives you:

```php
$this->toxiproxy();                                  // the shared Toxiproxy
$this->proxy('redis', '127.0.0.1:6379');             // get or create
$this->proxy('redis');                               // get, once created
$this->proxies(['redis' => '…', 'mysql' => '…']);    // several at once

$this->withLatency('redis', 1000, $callback);
$this->withServiceDown('mysql', $callback);
$this->withBandwidth('redis', 50, $callback);
$this->withTimeout('redis', 5000, $callback);
$this->withPacketLoss('redis', 0.25, $callback);
$this->withToxics('redis', [$toxic], $callback);
```

**One server per test process**, started on first use and shared. Between tests an
`#[After]` hook calls `reset()`, so a test that leaves a service broken cannot poison
the next one, while proxy definitions and their ports survive the whole class.

**No orphans.** The server is tied to the PHP process. Interrupt the suite with Ctrl-C
and it goes with it.

Point it somewhere else by overriding one method:

```php
protected function toxiproxyConfiguration(): Configuration
{
    return Configuration::fromEnvironment()->withPort(19474);
}
```

### Pest

Pest test cases are PHPUnit test cases, so the trait works unchanged. Wire it up once
in `tests/Pest.php`:

```php
uses(Mpge\Toxiproxy\Testing\InteractsWithToxiproxy::class)->in('Feature');
```

```php
it('survives a slow cache', function () {
    $this->proxy('redis', '127.0.0.1:6379');

    $this->withLatency('redis', 2000, function () {
        expect(checkout())->toBeSuccessful();
    });
});
```

Or skip the trait and use the facade directly:

```php
beforeEach(function () {
    $this->toxiproxy = Mpge\Toxiproxy\Toxiproxy::start();
    $this->redis = $this->toxiproxy->proxy('redis', '127.0.0.1:6379');
});

afterEach(fn () => $this->toxiproxy->reset());

it('retries when the cache resets the connection', function () {
    $this->redis->withPacketLoss(1.0, fn () => expect(cache()->get('k'))->toBeNull());
});
```

---

## Laravel

Laravel support is optional and auto-discovered. Nothing in the core package references
the framework.

```bash
php artisan vendor:publish --tag=toxiproxy-config
```

```php
// config/toxiproxy.php
return [
    'host' => env('TOXIPROXY_HOST', '127.0.0.1'),
    'port' => env('TOXIPROXY_PORT', 8474),

    'binary' => [
        'version' => env('TOXIPROXY_VERSION', '2.12.0'),
        'auto_install' => true,
    ],

    'proxies' => [
        'redis' => ['upstream' => '127.0.0.1:6379'],
        'mysql' => ['upstream' => '127.0.0.1:3306', 'listen' => '127.0.0.1:13306'],
    ],
];
```

```bash
php artisan toxiproxy:install
php artisan toxiproxy:start     # starts, then creates the configured proxies
php artisan toxiproxy:status
php artisan toxiproxy:stop
```

Resolve it from the container:

```php
use Mpge\Toxiproxy\Toxiproxy;

app(Toxiproxy::class)->proxy('redis')->latency(500);
```

Resolving the binding **connects** but does not start a server, because a container
binding should not spawn a process as a side effect. Set `auto_start => true` if you
want it to, or start it with `php artisan toxiproxy:start`.

The bindings are `Configuration`, `ToxiproxyClient`, `ToxiproxyServer` and `Toxiproxy`
(also aliased to `toxiproxy`).

---

## Command line

```bash
vendor/bin/toxiproxy-php install     # download the official binary
vendor/bin/toxiproxy-php start       # start a server in the background
vendor/bin/toxiproxy-php status      # what is running, and what it is proxying
vendor/bin/toxiproxy-php proxies     # list proxies and their toxics
vendor/bin/toxiproxy-php reset       # enable everything, drop every toxic
vendor/bin/toxiproxy-php stop        # stop a server this package started
vendor/bin/toxiproxy-php version     # package, binary and server versions
vendor/bin/toxiproxy-php update      # install the newest upstream release
vendor/bin/toxiproxy-php doctor      # diagnose the environment
```

Every command takes `--host`, `--port` and `--url`; the server-related ones also take
`--release`, `--binary`, `--home`, `--log-level`, `--timeout` and `--docker`.

`start` returns immediately, leaving the server running. Use `--foreground` to stay
attached and stream its log.

`doctor` checks PHP version, HTTP transport, platform support, cache writability,
binary presence and version drift, API reachability, whether the port is held by
something that is not Toxiproxy, server ownership, and Docker availability:

```
[ok]    PHP                    8.4.3
[ok]    Package                0.1.0
[ok]    HTTP transport         ext-curl
[ok]    Platform               linux/amd64
[ok]    Cache directory        /home/dev/.cache/toxiproxy-php
[ok]    Server binary          2.12.0  /home/dev/.cache/toxiproxy-php/bin/2.12.0/toxiproxy-server
[ok]    API                    http://127.0.0.1:8474  Toxiproxy 2.12.0
[ok]    Ownership              started by this package, pid 48213
[ok]    Proxy interface        127.0.0.1
[ok]    Proxies                2 defined
[ok]    Docker                 available
```

---

## Binary management

This package downloads the **official** `toxiproxy-server` binary from Shopify's GitHub
releases. Nothing is compiled, nothing is vendored, nothing is reimplemented.

Resolution order, most explicit first:

1. `TOXIPROXY_BINARY` — used verbatim.
2. This package's cache, keyed by version.
3. A `toxiproxy-server` already on `PATH`, from Homebrew, apt or anywhere else. Reused
   rather than duplicated; `doctor` tells you when this is what is in play, since its
   version is then outside your control.
4. Downloaded from GitHub Releases, if auto-install is on.

Downloads are verified against the `checksums.txt` published with the release. A
mismatch deletes the file and raises rather than leaving something that looks
installed.

The cache lives **outside `vendor/`** — `~/.cache/toxiproxy-php` on Linux,
`~/Library/Caches/toxiproxy-php` on macOS, `%LOCALAPPDATA%\toxiproxy-php` on Windows.
`vendor/` is disposable and rebuilt constantly in CI, and a multi-megabyte binary per
project is waste when one per machine will do. Override with `TOXIPROXY_HOME`.

The version is **pinned**, not floating. A test suite whose proxy server changes
underneath it on somebody else's release schedule is a flake waiting to happen. Move
deliberately:

```bash
vendor/bin/toxiproxy-php update              # install the newest release
TOXIPROXY_VERSION=2.13.0 vendor/bin/toxiproxy-php start
```

`TOXIPROXY_VERSION=latest` opts into tracking upstream if you would rather.

### Ownership

The rule this package enforces: **only stop what you started.**

`start()` adopts a server already answering on the endpoint instead of failing or
spawning a duplicate, and remembers that it did not start it. `stop()` on such a server
returns `false` and does nothing. Your `docker-compose` Toxiproxy, or one a colleague
left running, survives your test suite untouched.

Servers this package starts are recorded in `<cache>/run/`, so `toxiproxy-php stop`
works across process boundaries — and refuses any endpoint it finds no record for.

---

## Using a server you manage yourself

Nothing is installed, started or stopped:

```php
$toxiproxy = Toxiproxy::connect('http://toxiproxy:8474');
```

Or through the environment, which is usually easier in CI:

```bash
TOXIPROXY_URL=http://toxiproxy:8474
```

```php
$toxiproxy = Toxiproxy::connect();
```

The `ToxiproxyClient` is usable entirely on its own if you want none of the lifecycle
management:

```php
use Mpge\Toxiproxy\Client\ToxiproxyClient;

$client = new ToxiproxyClient('http://127.0.0.1:8474');

$proxy = $client->createProxy(
    name: 'mysql',
    upstream: '127.0.0.1:3306',
    listen: '127.0.0.1:13306',
);
```

### Transports

There is no HTTP client dependency. By default the package uses `ext-curl`, falling
back to stream wrappers. To route Toxiproxy calls through a client you already have:

```php
use Mpge\Toxiproxy\Client\Psr18Transport;

$transport = new Psr18Transport($psr18Client, $requestFactory, $streamFactory);

$toxiproxy = Toxiproxy::connect('http://127.0.0.1:8474', $transport);
```

Implement `Mpge\Toxiproxy\Client\Transport` for anything else. It has one method.

---

## Docker

Native binaries are the primary path. Docker is available if your stack already lives
there:

```php
Toxiproxy::docker()->start();
```

```bash
vendor/bin/toxiproxy-php start --docker
```

It runs `ghcr.io/shopify/toxiproxy`, the image Shopify publishes. Two things behave
differently in a container, and neither can be papered over:

**Upstreams resolve inside the container.** `127.0.0.1:6379` means the container's own
loopback, not your machine's. Use `host.docker.internal`, or a service name on a shared
Docker network.

**Proxy listen ports must be published.** A proxy listening on a port that was not
published when the container started is unreachable from the host, so declare the range
up front:

```php
Toxiproxy::docker()
    ->publish(30000, 30010)
    ->start();

$toxiproxy->proxy('redis', 'host.docker.internal:6379', '0.0.0.0:30000');
```

On Linux, host networking removes the problem entirely:

```php
Toxiproxy::docker()->network('host')->start();
```

Stop a container with `docker stop`, not `toxiproxy-php stop`.

---

## Continuous integration

Nothing special is needed. `Toxiproxy::start()` works on a bare runner, and caching the
binary keeps it to one download per release:

```yaml
- uses: actions/cache@v4
  with:
    path: ~/.cache/toxiproxy-php
    key: toxiproxy-${{ runner.os }}-2.12.0

- run: vendor/bin/toxiproxy-php install
- run: vendor/bin/phpunit
```

If you would rather run Toxiproxy as a service container, point the package at it and
turn auto-install off:

```yaml
services:
  toxiproxy:
    image: ghcr.io/shopify/toxiproxy:2.12.0
    ports: ['8474:8474']

env:
  TOXIPROXY_URL: http://127.0.0.1:8474
  TOXIPROXY_AUTO_INSTALL: '0'
```

---

## Configuration

Environment variables, all optional:

| Variable | Default | What it does |
|---|---|---|
| `TOXIPROXY_HOST` | `127.0.0.1` | Host the API listens on |
| `TOXIPROXY_PORT` | `8474` | Port the API listens on |
| `TOXIPROXY_URL` | — | Full base URL, overriding host and port together |
| `TOXIPROXY_VERSION` | `2.12.0` | Release to install, or `latest` |
| `TOXIPROXY_BINARY` | — | An explicit server binary, skipping the cache |
| `TOXIPROXY_HOME` | per-OS cache dir | Where binaries and run records are kept |
| `TOXIPROXY_AUTO_INSTALL` | `true` | Download the binary on demand |
| `TOXIPROXY_VERIFY_CHECKSUMS` | `true` | Verify downloads against the release checksums |
| `TOXIPROXY_LOG_LEVEL` | `info` | Server log level: `trace`, `debug`, `info`, `warn`, `error` |
| `TOXIPROXY_START_TIMEOUT` | `15` | Seconds to wait for the API to answer after spawning |
| `TOXIPROXY_PROXY_HOST` | `127.0.0.1` | Interface new proxies bind to |
| `TOXIPROXY_DEBUG` | `false` | Extra diagnostic output |

Or in code:

```php
use Mpge\Toxiproxy\Configuration;

$toxiproxy = Toxiproxy::make()
    ->port(19474)
    ->version('2.11.0')
    ->logLevel('debug')
    ->startTimeout(30)
    ->start();

// Or build a Configuration and pass it around.
$config = Configuration::fromEnvironment()->withPort(19474);
```

---

## Supported platforms

The platforms Shopify publishes a server binary for:

| OS | amd64 | arm64 |
|---|---|---|
| Linux | ✅ | ✅ |
| macOS | ✅ | ✅ |
| Windows | ✅ | — |
| FreeBSD | ✅ | ✅ |
| OpenBSD | ✅ | ✅ |
| NetBSD | ✅ | — |
| Solaris | ✅ | — |

On anything else the client still works; point `TOXIPROXY_BINARY` at a server you built
yourself, or connect to one over the network.

---

## Testing against third-party APIs

Toxiproxy sits in front of things that exist locally: your Redis, your MySQL, a
service in your compose file. It cannot help with the half of your dependencies
that live on somebody else's servers — Stripe, Shopify, Twilio — because there is
nothing local for it to proxy.

For those, [**Cauldron**](https://github.com/CauldronUp/cauldron) boots working
emulations of the providers your project talks to, locally, from one command. It
reads the manifests already in your repo, works out which third-party APIs you
depend on, and serves fakes for them.

Its `cauldron network` command deliberately uses the same vocabulary as this
package — latency, jitter, bandwidth, timeout, reset, slice, limit — so you do not
learn two sets of words for one idea:

```bash
cauldron network stripe --latency 800ms --jitter 200ms
cauldron network stripe --reset --probability 0.1
```

The two compose. Point Toxiproxy at your database and Cauldron at your payment
provider, and every dependency your application has can be made to misbehave from
a single test:

```php
$redis = $toxiproxy->proxy('redis', '127.0.0.1:6379');

$redis->withLatency(1000, function () {
    // Redis is slow, and Cauldron has Stripe resetting one call in ten.
    $this->assertTrue($this->checkout()->succeeded());
});
```

Cauldron is a separate project, Go rather than PHP, and not required by anything
here.

---

## Troubleshooting

**`vendor/bin/toxiproxy-php doctor`** answers most of what follows. Try it first.

**"Cannot start Toxiproxy: 127.0.0.1:8474 is already in use"**
Something holds the port. If it is a Toxiproxy you started elsewhere, use it —
`Toxiproxy::start()` will adopt it. If it is something else, pick another port with
`TOXIPROXY_PORT`.

**"Refusing to stop the Toxiproxy server ... because this process did not start it"**
Working as intended. The server has no record in `<cache>/run/`, so it belongs to
something else. Stop it however you started it.

**A toxic is accepted but nothing happens.**
Almost always a misspelled attribute name; the Go server drops unknown keys silently.
This package validates them, so use `latency()` and friends rather than hand-building
`addToxic()` payloads, and check the attribute names against the table in
[Toxics](#toxics) if you must.

**Latency looks doubled.**
A toxic applies per direction. `latency(1000)` on downstream adds 1000ms to the reply;
adding another on upstream adds 1000ms to the request as well.

**"Could not reach the Toxiproxy API"**
The server is not running, or is on a different port. `toxiproxy-php status` will say
which.

**HTTP 403 from the API.**
Toxiproxy refuses any request whose `User-Agent` starts with `Mozilla/`, as a guard
against poking the control plane from a browser. Only relevant if you supplied a custom
transport with a browser-like agent.

**Connection refused straight after `start()` returns.**
`start()` waits for the API to answer before returning, but the *proxy* ports come up
when the proxy is created. If you cached a port from an earlier run, re-read it from
`$proxy->port()`.

**Downloads fail behind a proxy.**
The downloader honours curl's usual `HTTP_PROXY` / `HTTPS_PROXY` environment. Failing
that, download the binary by hand and set `TOXIPROXY_BINARY`.

---

## How this differs from `ihsw/toxiproxy-php-client`

Shopify's documentation points at [`ihsw/toxiproxy-php-client`](https://github.com/ihsw/toxiproxy-php-client),
which is a perfectly reasonable HTTP client and has been around a lot longer. It is
worth being explicit about what is different rather than implying it is bad.

| | `ihsw/toxiproxy-php-client` | `mpge/toxiproxy-php` |
|---|---|---|
| HTTP API client | ✅ | ✅ |
| On Packagist | ❌ (needs a VCS repository entry) | ✅ |
| Server binary management | ❌ | ✅ download, verify, cache |
| Server process lifecycle | ❌ | ✅ start, stop, adopt, no orphans |
| Composer CLI | ❌ | ✅ `vendor/bin/toxiproxy-php` |
| PHPUnit / Pest helpers | ❌ | ✅ |
| Scoped chaos (`withLatency`, `down`) | ❌ | ✅ |
| Automatic port allocation | ❌ | ✅ delegated to the server |
| Laravel integration | ❌ | ✅ optional |
| `reset_peer` toxic | ❌ missing from its enum | ✅ |
| HTTP dependency | Guzzle 7 | none |
| Minimum PHP | 8.3 | 8.2 |

If all you want is a thin client and you already have Guzzle, `ihsw` will serve you
fine. This package exists for the case where you want Toxiproxy to be one `composer
require` and nothing else.

---

## Architecture

```
PHP application / tests
        |
        v
mpge/toxiproxy-php          <- this package
        |
        | HTTP API (:8474)
        v
Shopify toxiproxy-server    <- the official Go binary, unmodified
        |
        | TCP
        v
Redis / MySQL / HTTP service / …
```

The proxying is Shopify's Go server, downloaded from their releases and run as-is. This
package never touches a packet. It speaks the HTTP control API and manages the process
lifecycle, and that is the whole of it.

Inside, the layers are separable and each usable on its own:

```
src/
    Toxiproxy.php            the facade
    PendingToxiproxy.php     fluent configuration before start
    Configuration.php        every knob, immutable

    Client/                  the HTTP API: transports, JSON, errors
    Proxy/                   Proxy, collections, addresses, port allocation
    Toxic/                   Toxic, its types and directions
    Server/                  platform detection, downloads, process lifecycle, Docker
    Testing/                 the PHPUnit trait
    Console/                 vendor/bin/toxiproxy-php
    Laravel/                 optional framework integration
    Exception/
```

---

## Contributing

```bash
composer install
composer test:unit          # no network, no processes
composer test:integration   # drives a real server; downloads the binary once
composer stan
composer lint               # composer fix to apply
composer ci                 # all of the above
```

Integration tests run on port 18474 rather than 8474, so a Toxiproxy you already have
running is neither disturbed nor mistaken for one of theirs.

---

## Licence and attribution

MIT. See [LICENSE](LICENSE).

**This is not an official Shopify project** and is not affiliated with, endorsed by, or
sponsored by Shopify.

[Toxiproxy](https://github.com/Shopify/toxiproxy) is a separate upstream project,
copyright Shopify Inc., also MIT licensed. When this package downloads a Toxiproxy
server binary, that binary remains the property of its authors and is governed by
upstream's licence, a copy of which ships inside every Toxiproxy release.

If Toxiproxy is useful to you, the credit belongs there.
