<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API endpoint
    |--------------------------------------------------------------------------
    |
    | Where the Toxiproxy control API listens. Point this at a service host in
    | CI (TOXIPROXY_URL also works and overrides both at once).
    |
    */

    'host' => env('TOXIPROXY_HOST', '127.0.0.1'),

    'port' => env('TOXIPROXY_PORT', 8474),

    /*
    |--------------------------------------------------------------------------
    | Proxy interface
    |--------------------------------------------------------------------------
    |
    | The interface new proxies bind to. The loopback default keeps your fault
    | injection off the network; widen it only if something outside this
    | machine has to reach a proxy.
    |
    */

    'proxy_host' => env('TOXIPROXY_PROXY_HOST', '127.0.0.1'),

    /*
    |--------------------------------------------------------------------------
    | Server binary
    |--------------------------------------------------------------------------
    |
    | version      The upstream release to install. Pinned rather than tracking
    |              latest, so nobody else's release schedule can change your
    |              test suite's behaviour overnight. "latest" opts out.
    |
    | auto_install Download the binary on first use instead of erroring. Turn
    |              this off in CI once the binary is a cached artifact.
    |
    | path         An explicit binary to use as-is, skipping download entirely.
    |
    | home         Where downloaded binaries are cached. Defaults to the
    |              per-machine cache directory, never vendor/.
    |
    | verify       Check downloads against the release checksums file.
    |
    */

    'binary' => [
        'version' => env('TOXIPROXY_VERSION', '2.12.0'),
        'auto_install' => env('TOXIPROXY_AUTO_INSTALL', true),
        'path' => env('TOXIPROXY_BINARY'),
        'home' => env('TOXIPROXY_HOME'),
        'verify' => env('TOXIPROXY_VERIFY_CHECKSUMS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server process
    |--------------------------------------------------------------------------
    |
    | auto_start  Start a server when the Toxiproxy binding is first resolved.
    |             Off by default: resolving a container binding should not
    |             quietly spawn a process. Use `php artisan toxiproxy:start`, or
    |             the InteractsWithToxiproxy trait, which starts one per test
    |             process and cleans up after itself.
    |
    | log_level   Passed to the server: trace, debug, info, warn, error.
    |
    | timeout     Seconds to wait for the API to answer after spawning.
    |
    */

    'auto_start' => env('TOXIPROXY_AUTO_START', false),

    'log_level' => env('TOXIPROXY_LOG_LEVEL', 'info'),

    'timeout' => env('TOXIPROXY_START_TIMEOUT', 15.0),

    /*
    |--------------------------------------------------------------------------
    | Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies to create when `php artisan toxiproxy:start` runs. Leave the
    | listen address out and Toxiproxy picks a free port; read it back with
    | `php artisan toxiproxy:status`.
    |
    |   'redis' => ['upstream' => '127.0.0.1:6379', 'listen' => '127.0.0.1:16379'],
    |
    */

    'proxies' => [
        //
    ],

];
