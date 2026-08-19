<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Laravel;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Laravel\ToxiproxyServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * The published config array is the only surface between Laravel and this
 * package's own Configuration, so it is the part worth testing directly.
 */
final class ServiceProviderConfigurationTest extends TestCase
{
    public function test_it_translates_the_published_config_shape(): void
    {
        $config = ToxiproxyServiceProvider::configurationFrom([
            'host' => '10.0.0.5',
            'port' => 9474,
            'proxy_host' => '0.0.0.0',
            'log_level' => 'debug',
            'timeout' => 30,
            'binary' => [
                'version' => '2.11.0',
                'auto_install' => false,
                'path' => '/opt/toxiproxy-server',
                'home' => '/var/cache/toxiproxy',
                'verify' => false,
            ],
        ]);

        self::assertSame('http://10.0.0.5:9474', $config->apiUrl());
        self::assertSame('0.0.0.0', $config->proxyHost);
        self::assertSame('debug', $config->logLevel);
        self::assertSame(30.0, $config->startTimeout);
        self::assertSame('2.11.0', $config->version);
        self::assertFalse($config->autoInstall);
        self::assertSame('/opt/toxiproxy-server', $config->binary);
        self::assertSame('/var/cache/toxiproxy', $config->home);
        self::assertFalse($config->verifyChecksums);
    }

    /**
     * An absent key falls through to the package's own environment-derived
     * defaults, so TOXIPROXY_* still works in an application that published the
     * config but left an entry alone.
     */
    public function test_an_empty_config_falls_back_to_the_package_defaults(): void
    {
        $defaults = Configuration::fromEnvironment();
        $config = ToxiproxyServiceProvider::configurationFrom([]);

        self::assertSame($defaults->apiUrl(), $config->apiUrl());
        self::assertSame($defaults->autoInstall, $config->autoInstall);
        self::assertSame($defaults->binary, $config->binary);
    }

    /**
     * env() returns null for an unset variable, and the published config passes
     * that straight through. Treating it as a value would produce a
     * Configuration with an empty version string.
     */
    public function test_null_values_from_env_fall_back_rather_than_becoming_empty_strings(): void
    {
        $defaults = Configuration::fromEnvironment();

        $config = ToxiproxyServiceProvider::configurationFrom([
            'host' => null,
            'port' => null,
            'binary' => ['version' => null, 'path' => null, 'home' => null],
        ]);

        self::assertSame($defaults->host, $config->host);
        self::assertSame($defaults->port, $config->port);
        self::assertNotSame('', $config->version);
        self::assertSame($defaults->binary, $config->binary);
    }

    /**
     * A .env file yields strings, never integers.
     */
    public function test_a_port_arriving_as_a_string_is_still_understood(): void
    {
        self::assertSame(9474, ToxiproxyServiceProvider::configurationFrom(['port' => '9474'])->port);
    }

    public function test_a_malformed_binary_section_is_ignored(): void
    {
        $config = ToxiproxyServiceProvider::configurationFrom(['binary' => 'not an array']);

        self::assertTrue($config->autoInstall);
        self::assertNull($config->binary);
    }

    public function test_the_shipped_config_file_matches_the_shape_the_provider_reads(): void
    {
        $path = dirname(__DIR__, 3).'/config/toxiproxy.php';

        self::assertFileExists($path);

        $source = (string) file_get_contents($path);

        foreach (['host', 'port', 'proxy_host', 'log_level', 'timeout', 'auto_start', 'binary', 'proxies'] as $key) {
            self::assertStringContainsString("'".$key."'", $source, sprintf('config/toxiproxy.php should define "%s".', $key));
        }

        foreach (['version', 'auto_install', 'path', 'home', 'verify'] as $key) {
            self::assertStringContainsString("'".$key."'", $source);
        }
    }
}
