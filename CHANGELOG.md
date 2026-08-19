# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-19

First release.

### Added

- **Toxiproxy HTTP API client** covering every documented endpoint: server version,
  proxy create / read / update / delete, enable and disable, toxic create / read /
  update / delete, `populate` and `reset`. Proxies and toxics are typed objects rather
  than arrays.
- **Server binary management.** Downloads the official `toxiproxy-server` release for
  the current platform, verifies it against the release `checksums.txt`, and caches it
  outside `vendor/` so one binary serves every project on the machine. Honours an
  explicit `TOXIPROXY_BINARY`, and reuses a `toxiproxy-server` already on `PATH` rather
  than duplicating it.
- **Server process lifecycle** with ownership tracking. A server already answering on
  the endpoint is adopted rather than duplicated, and never stopped. Servers this
  package starts are recorded on disk so a later process can stop them, and attached
  servers die with the PHP process so a test run leaves no orphan.
- **Ergonomic proxy and toxic API**: `latency`, `bandwidth`, `timeout`, `slowClose`,
  `resetPeer`, `slicer`, `limitData`, `noop` and `packetLoss`, with explicit
  `upstream()` / `downstream()` selection and a generic `addToxic()` escape hatch.
  Attribute names are validated before the request is sent, because the Go server
  silently drops keys it does not recognise.
- **Scoped chaos helpers** — `withLatency()`, `down()`, `withToxics()` and the rest —
  which restore the proxy's full previous state in a `finally` block, so a failing
  assertion cannot leak a toxic into the next test.
- **Automatic port allocation**, delegated to Toxiproxy by asking it to bind port 0,
  which avoids the race inherent in picking a port in PHP.
- **`vendor/bin/toxiproxy-php`** with `install`, `start`, `stop`, `status`, `version`,
  `proxies`, `reset`, `update` and `doctor`.
- **PHPUnit trait** `InteractsWithToxiproxy`, which starts one server per test process,
  shares it, and resets every toxic between tests. Works unchanged in Pest.
- **Optional Laravel integration**: auto-discovered service provider, publishable
  config, and `toxiproxy:install`, `toxiproxy:start`, `toxiproxy:stop`,
  `toxiproxy:status` artisan commands. The core package is framework agnostic.
- **Optional Docker support** via `Toxiproxy::docker()` and `--docker`, using the
  `ghcr.io/shopify/toxiproxy` image.
- **Pluggable HTTP transport.** No HTTP client dependency: a built-in curl transport
  with a stream-wrapper fallback, plus a PSR-18 bridge for projects that would rather
  use their own.

### Notes

- `packetLoss()` is a `reset_peer` toxic at the given toxicity, because Toxiproxy has
  no packet-level loss toxic. It drops connections, not packets. See the README.
- The Toxiproxy version is pinned to 2.12.0 rather than tracking `latest`, so upstream's
  release schedule cannot change a test suite's behaviour without a deliberate change
  here. `TOXIPROXY_VERSION=latest` opts out.

[Unreleased]: https://github.com/mpge/toxiproxy-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/mpge/toxiproxy-php/releases/tag/v0.1.0
