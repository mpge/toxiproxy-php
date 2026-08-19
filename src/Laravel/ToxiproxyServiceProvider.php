<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Laravel\Console\InstallCommand;
use Mpge\Toxiproxy\Laravel\Console\StartCommand;
use Mpge\Toxiproxy\Laravel\Console\StatusCommand;
use Mpge\Toxiproxy\Laravel\Console\StopCommand;
use Mpge\Toxiproxy\Server\ToxiproxyServer;
use Mpge\Toxiproxy\Toxiproxy;

/**
 * Optional Laravel wiring, auto-discovered when the framework is present.
 *
 * Nothing in the core package depends on Laravel; this file is only ever
 * autoloaded by an application that has it.
 */
final class ToxiproxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'toxiproxy');

        $this->app->singleton(Configuration::class, function ($app): Configuration {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            /** @var mixed $published */
            $published = $config->get('toxiproxy', []);

            return self::configurationFrom(is_array($published) ? $published : []);
        });

        $this->app->singleton(ToxiproxyServer::class, fn ($app): ToxiproxyServer => ToxiproxyServer::create(
            $app->make(Configuration::class),
        ));

        $this->app->singleton(ToxiproxyClient::class, function ($app): ToxiproxyClient {
            /** @var Configuration $config */
            $config = $app->make(Configuration::class);

            return new ToxiproxyClient($config->apiUrl());
        });

        $this->app->singleton(Toxiproxy::class, function ($app): Toxiproxy {
            /** @var Configuration $config */
            $config = $app->make(Configuration::class);
            /** @var ConfigRepository $laravelConfig */
            $laravelConfig = $app->make('config');

            // Resolving a container binding should never spawn a process by
            // surprise, so starting is opt-in.
            return $laravelConfig->get('toxiproxy.auto_start', false) === true
                ? Toxiproxy::make($config)->start()
                : Toxiproxy::connect($config);
        });

        $this->app->alias(Toxiproxy::class, 'toxiproxy');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => $this->app->configPath('toxiproxy.php'),
        ], 'toxiproxy-config');

        $this->commands([
            InstallCommand::class,
            StartCommand::class,
            StopCommand::class,
            StatusCommand::class,
        ]);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            Configuration::class,
            ToxiproxyServer::class,
            ToxiproxyClient::class,
            Toxiproxy::class,
            'toxiproxy',
        ];
    }

    /**
     * Translate the published config array into the package's Configuration.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function configurationFrom(array $config): Configuration
    {
        $defaults = Configuration::fromEnvironment();
        /** @var array<string, mixed> $binary */
        $binary = is_array($config['binary'] ?? null) ? $config['binary'] : [];

        return new Configuration(
            host: self::string($config, 'host', $defaults->host),
            port: self::integer($config, 'port', $defaults->port),
            binary: self::nullableString($binary, 'path', $defaults->binary),
            version: self::string($binary, 'version', $defaults->version),
            autoInstall: self::boolean($binary, 'auto_install', $defaults->autoInstall),
            home: self::nullableString($binary, 'home', $defaults->home),
            logLevel: self::string($config, 'log_level', $defaults->logLevel),
            startTimeout: self::float($config, 'timeout', $defaults->startTimeout),
            proxyHost: self::string($config, 'proxy_host', $defaults->proxyHost),
            verifyChecksums: self::boolean($binary, 'verify', $defaults->verifyChecksums),
            debug: $defaults->debug,
        );
    }

    private function configPath(): string
    {
        return dirname(__DIR__, 2).'/config/toxiproxy.php';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function string(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function nullableString(array $values, string $key, ?string $default): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function integer(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function float(array $values, string $key, float $default): float
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function boolean(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }
}
