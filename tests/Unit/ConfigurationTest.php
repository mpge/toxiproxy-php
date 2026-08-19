<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Server\Platform;
use Mpge\Toxiproxy\Support\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_the_defaults_work_with_no_configuration_at_all(): void
    {
        $config = new Configuration();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8474, $config->port);
        self::assertSame('http://127.0.0.1:8474', $config->apiUrl());
        self::assertTrue($config->autoInstall);
        self::assertTrue($config->verifyChecksums);
        self::assertSame('127.0.0.1', $config->proxyHost);
    }

    public function test_it_reads_every_environment_variable(): void
    {
        $config = Configuration::fromEnvironment(Environment::fake([
            'TOXIPROXY_HOST' => '10.0.0.5',
            'TOXIPROXY_PORT' => '9474',
            'TOXIPROXY_VERSION' => '2.11.0',
            'TOXIPROXY_BINARY' => '/opt/toxiproxy-server',
            'TOXIPROXY_AUTO_INSTALL' => 'false',
            'TOXIPROXY_LOG_LEVEL' => 'debug',
            'TOXIPROXY_HOME' => '/var/cache/toxiproxy',
            'TOXIPROXY_PROXY_HOST' => '0.0.0.0',
            'TOXIPROXY_START_TIMEOUT' => '30',
            'TOXIPROXY_VERIFY_CHECKSUMS' => '0',
            'TOXIPROXY_DEBUG' => 'yes',
        ]));

        self::assertSame('10.0.0.5', $config->host);
        self::assertSame(9474, $config->port);
        self::assertSame('2.11.0', $config->version);
        self::assertSame('/opt/toxiproxy-server', $config->binary);
        self::assertFalse($config->autoInstall);
        self::assertSame('debug', $config->logLevel);
        self::assertSame('/var/cache/toxiproxy', $config->home);
        self::assertSame('0.0.0.0', $config->proxyHost);
        self::assertSame(30.0, $config->startTimeout);
        self::assertFalse($config->verifyChecksums);
        self::assertTrue($config->debug);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanStrings(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
        yield 'no' => ['no', false];
        yield 'off' => ['off', false];
    }

    #[DataProvider('booleanStrings')]
    public function test_it_understands_the_usual_boolean_spellings(string $value, bool $expected): void
    {
        $config = Configuration::fromEnvironment(Environment::fake(['TOXIPROXY_AUTO_INSTALL' => $value]));

        self::assertSame($expected, $config->autoInstall);
    }

    public function test_an_unparseable_boolean_keeps_the_default_rather_than_guessing(): void
    {
        $config = Configuration::fromEnvironment(Environment::fake(['TOXIPROXY_AUTO_INSTALL' => 'maybe']));

        self::assertTrue($config->autoInstall);
    }

    /**
     * CI usually runs Toxiproxy as a named service, where one URL is easier to
     * inject than a host and a port.
     */
    public function test_a_url_overrides_host_and_port_together(): void
    {
        $config = Configuration::fromEnvironment(Environment::fake([
            'TOXIPROXY_URL' => 'http://toxiproxy:8474',
        ]));

        self::assertSame('toxiproxy', $config->host);
        self::assertSame(8474, $config->port);
    }

    public function test_a_url_without_a_scheme_still_parses(): void
    {
        $config = Configuration::fromEnvironment(Environment::fake(['TOXIPROXY_URL' => 'toxiproxy:9000']));

        self::assertSame('toxiproxy', $config->host);
        self::assertSame(9000, $config->port);
    }

    public function test_a_url_without_a_port_falls_back_to_the_upstream_default(): void
    {
        $config = Configuration::fromEnvironment(Environment::fake(['TOXIPROXY_URL' => 'http://toxiproxy']));

        self::assertSame(8474, $config->port);
    }

    public function test_a_url_that_is_not_a_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Configuration::fromEnvironment(Environment::fake(['TOXIPROXY_URL' => '///']));
    }

    public function test_an_empty_variable_is_treated_as_unset(): void
    {
        $config = Configuration::fromEnvironment(Environment::fake(['TOXIPROXY_HOST' => '']));

        self::assertSame('127.0.0.1', $config->host);
    }

    public function test_an_out_of_range_port_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Configuration(port: 70000);
    }

    public function test_a_non_positive_start_timeout_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Configuration(startTimeout: 0.0);
    }

    /**
     * @return iterable<string, array{Platform, array<string, string>, string}>
     */
    public static function homeDirectories(): iterable
    {
        yield 'linux xdg' => [
            new Platform('linux', 'amd64'),
            ['XDG_CACHE_HOME' => '/xdg', 'HOME' => '/home/dev'],
            '/xdg/toxiproxy-php',
        ];

        yield 'linux without xdg' => [
            new Platform('linux', 'amd64'),
            ['HOME' => '/home/dev'],
            '/home/dev/.cache/toxiproxy-php',
        ];

        yield 'macos' => [
            new Platform('darwin', 'arm64'),
            ['HOME' => '/Users/dev'],
            '/Users/dev/Library/Caches/toxiproxy-php',
        ];
    }

    /**
     * Reading the real environment here would make the assertion depend on the
     * machine, so only the explicit override is checked exhaustively; the
     * platform-specific defaults are asserted through defaultHome() below.
     */
    public function test_an_explicit_home_is_used_verbatim(): void
    {
        $config = (new Configuration())->withHome('/tmp/toxiproxy-cache/');

        self::assertSame('/tmp/toxiproxy-cache', $config->homeDirectory(new Platform('linux', 'amd64')));
    }

    public function test_the_default_home_is_never_inside_vendor(): void
    {
        $home = Configuration::defaultHome(Platform::current());

        self::assertNotSame('', $home);
        self::assertStringNotContainsString('vendor', $home);
        self::assertStringContainsString('toxiproxy-php', $home);
    }

    public function test_withers_do_not_mutate_the_original(): void
    {
        $original = new Configuration();
        $changed = $original->withHost('example.test')->withPort(1234)->withDebug(true);

        self::assertSame('127.0.0.1', $original->host);
        self::assertSame(8474, $original->port);
        self::assertFalse($original->debug);

        self::assertSame('example.test', $changed->host);
        self::assertSame(1234, $changed->port);
        self::assertTrue($changed->debug);
    }

    /**
     * These two take null to mean "clear the override", unlike the other
     * withers, so they need their own check.
     */
    public function test_passing_null_clears_the_binary_and_home_overrides(): void
    {
        $config = (new Configuration(binary: '/opt/x', home: '/opt/y'))
            ->withBinary(null)
            ->withHome(null);

        self::assertNull($config->binary);
        self::assertNull($config->home);
    }

    public function test_latest_is_recognised_but_never_used_as_a_path_component(): void
    {
        $config = (new Configuration())->withVersion('latest');

        self::assertTrue($config->wantsLatest());
        // release() must still hand back something concrete so a cache path can
        // be built before the network is consulted.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $config->release()->version);
    }

    public function test_ipv6_hosts_are_bracketed_in_the_api_url(): void
    {
        self::assertSame('http://[::1]:8474', (new Configuration(host: '::1'))->apiUrl());
    }
}
