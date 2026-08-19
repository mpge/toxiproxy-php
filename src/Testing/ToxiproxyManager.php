<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Testing;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Toxiproxy;

/**
 * One Toxiproxy per test process, shared by every test that asks for it.
 *
 * A test suite that starts a Go process per test case is a slow test suite, and
 * Toxiproxy is cheap to reuse: reset() between tests puts it back to a clean
 * state in one HTTP call.
 *
 * The instance is keyed by endpoint, so a suite that deliberately talks to two
 * servers gets two.
 */
final class ToxiproxyManager
{
    /** @var array<string, Toxiproxy> */
    private static array $instances = [];

    /** @var array<string, bool> */
    private static array $shutdownRegistered = [];

    public static function instance(?Configuration $config = null): Toxiproxy
    {
        $config ??= Configuration::fromEnvironment();
        $key = $config->apiUrl();

        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        $toxiproxy = Toxiproxy::make($config)->start();
        self::$instances[$key] = $toxiproxy;
        self::registerShutdown($key);

        return $toxiproxy;
    }

    public static function has(?Configuration $config = null): bool
    {
        return isset(self::$instances[($config ?? Configuration::fromEnvironment())->apiUrl()]);
    }

    /**
     * Forget the shared instance, stopping the server if we started it.
     *
     * Rarely needed: the shutdown hook does this at the end of the process.
     */
    public static function shutdown(?Configuration $config = null): void
    {
        $key = ($config ?? Configuration::fromEnvironment())->apiUrl();
        $toxiproxy = self::$instances[$key] ?? null;

        if ($toxiproxy === null) {
            return;
        }

        unset(self::$instances[$key]);

        $toxiproxy->stop();
    }

    public static function shutdownAll(): void
    {
        foreach (array_keys(self::$instances) as $key) {
            $toxiproxy = self::$instances[$key];
            unset(self::$instances[$key]);
            $toxiproxy->stop();
        }
    }

    private static function registerShutdown(string $key): void
    {
        if (isset(self::$shutdownRegistered[$key])) {
            return;
        }

        self::$shutdownRegistered[$key] = true;

        register_shutdown_function(static function () use ($key): void {
            $toxiproxy = self::$instances[$key] ?? null;

            if ($toxiproxy !== null) {
                unset(self::$instances[$key]);
                $toxiproxy->stop(2.0);
            }
        });
    }
}
