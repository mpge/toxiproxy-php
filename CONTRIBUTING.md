# Contributing

Thanks for looking. This is a small package with a narrow job, so most changes are
straightforward.

## Getting set up

```bash
git clone https://github.com/mpge/toxiproxy-php
cd toxiproxy-php
composer install
```

## Running things

```bash
composer test:unit          # fast, no network, no processes, no server
composer test:integration   # drives a real Toxiproxy; downloads the binary once
composer stan               # PHPStan, level 9
composer lint               # PHP-CS-Fixer, dry run
composer fix                # PHP-CS-Fixer, applied
composer ci                 # everything CI runs, except integration tests
```

Integration tests use port 18474 rather than Toxiproxy's default 8474, so a server you
already have running is neither disturbed nor mistaken for one of ours.

## The one rule about the upstream server

**Do not reimplement any part of Toxiproxy's networking.** This package exists to make
Shopify's Go server frictionless from PHP; the moment it starts proxying packets itself
it becomes a fork with a worse maintenance story. The architecture stays:

```
PHP → this package → HTTP API → toxiproxy-server → TCP → your dependency
```

Where the PHP API and upstream behaviour disagree, upstream wins and the abstraction is
designed around it. `packetLoss()` is the worked example: Toxiproxy has no packet-loss
toxic, so the helper maps onto `reset_peer` with a toxicity, and says so in its
docblock and in the README rather than quietly implying otherwise.

## Tests

- **Unit tests** get fake transports and downloaders (`tests/Support`). They must not
  touch the network or spawn a process.
- **Integration tests** run against a real server. Where an effect is observable in a
  reasonable time, measure it. Asserting that the API accepted a toxic proves very
  little on its own: the Go server decodes attributes into a typed struct and silently
  discards keys it does not recognise, so a misspelled attribute produces a toxic that
  is created, is listed, and does nothing.

## Following upstream

When a new Toxiproxy release lands:

1. `vendor/bin/toxiproxy-php update` to fetch it.
2. Run the integration suite against it: `TOXIPROXY_VERSION=x.y.z composer test:integration`.
3. If a new toxic type or attribute appeared, add it to `ToxicType` — the attribute
   names must match the `json` tags on upstream's structs in `toxics/`, and
   `ToxicTest::test_attribute_names_mirror_the_upstream_go_structs` is what keeps them
   honest.
4. Bump `Release::DEFAULT_VERSION` and note it in the changelog.

## Pull requests

- One concern per PR.
- Keep `composer ci` green.
- Add a changelog entry under `## [Unreleased]`.
- Explain *why* in the commit body when the reason is not obvious from the diff. Most
  of the comments in this codebase exist because something upstream is surprising;
  that context is worth writing down.
