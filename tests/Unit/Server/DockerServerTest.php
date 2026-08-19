<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Server\DockerServer;
use PHPUnit\Framework\TestCase;

final class DockerServerTest extends TestCase
{
    public function test_the_default_image_is_the_one_shopify_publishes(): void
    {
        $server = new DockerServer((new Configuration())->withVersion('2.12.0'));

        self::assertSame('ghcr.io/shopify/toxiproxy:2.12.0', $server->image());
    }

    public function test_the_image_tag_follows_the_configured_version(): void
    {
        $server = new DockerServer((new Configuration())->withVersion('2.11.0'));

        self::assertSame('ghcr.io/shopify/toxiproxy:2.11.0', $server->image());
    }

    public function test_the_container_name_is_derived_from_the_port_so_two_can_coexist(): void
    {
        self::assertSame('toxiproxy-php-8474', (new DockerServer(new Configuration()))->containerName());
        self::assertSame('toxiproxy-php-9474', (new DockerServer(new Configuration(port: 9474)))->containerName());
    }

    /**
     * The container must listen on 0.0.0.0 internally or the published port
     * maps to a socket nothing is bound to; the host side is still restricted
     * to the configured host.
     */
    public function test_the_run_command_publishes_the_api_port_and_listens_broadly_inside(): void
    {
        $command = (new DockerServer(new Configuration(host: '127.0.0.1', port: 9474)))->runCommand('docker', 'tp');

        self::assertSame([
            'docker', 'run', '--detach', '--rm', '--name', 'tp',
            '--publish', '127.0.0.1:9474:8474',
            '--env', 'LOG_LEVEL=info',
            'ghcr.io/shopify/toxiproxy:'.(new Configuration())->release()->version,
            '-host=0.0.0.0',
            '-port=8474',
        ], $command);
    }

    /**
     * A proxy listening on a port that was not published is unreachable from
     * the host, so the range has to be declared up front.
     */
    public function test_extra_port_ranges_are_published_for_the_proxies(): void
    {
        $command = (new DockerServer(
            new Configuration(),
            publishedRanges: [[30000, 30010], [40000, 40001]],
        ))->runCommand('docker', 'tp');

        self::assertContains('127.0.0.1:30000-30010:30000-30010', $command);
        self::assertContains('127.0.0.1:40000-40001:40000-40001', $command);
    }

    /**
     * Docker rejects --publish alongside --network host, and host networking
     * removes the need for it anyway.
     */
    public function test_host_networking_replaces_publishing_rather_than_joining_it(): void
    {
        $command = (new DockerServer(
            new Configuration(),
            publishedRanges: [[30000, 30010]],
            network: 'host',
        ))->runCommand('docker', 'tp');

        self::assertContains('--network', $command);
        self::assertContains('host', $command);
        self::assertNotContains('--publish', $command);
    }

    public function test_a_named_network_still_publishes(): void
    {
        $command = (new DockerServer(new Configuration(), network: 'my-app'))->runCommand('docker', 'tp');

        self::assertContains('--network', $command);
        self::assertContains('my-app', $command);
        self::assertContains('--publish', $command);
    }

    public function test_extra_arguments_land_before_the_image(): void
    {
        $command = (new DockerServer(
            new Configuration(),
            extraArguments: ['--memory', '256m'],
        ))->runCommand('docker', 'tp');

        $imageIndex = array_search('ghcr.io/shopify/toxiproxy:'.(new Configuration())->release()->version, $command, true);
        $memoryIndex = array_search('--memory', $command, true);

        self::assertIsInt($imageIndex);
        self::assertIsInt($memoryIndex);
        self::assertLessThan($imageIndex, $memoryIndex);
    }

    public function test_a_custom_image_overrides_the_default_entirely(): void
    {
        $server = new DockerServer(new Configuration(), image: 'my-registry/toxiproxy:custom');

        self::assertSame('my-registry/toxiproxy:custom', $server->image());
        self::assertContains('my-registry/toxiproxy:custom', $server->runCommand('docker', 'tp'));
    }

    public function test_the_log_level_reaches_the_container(): void
    {
        $command = (new DockerServer((new Configuration())->withLogLevel('debug')))->runCommand('docker', 'tp');

        self::assertContains('LOG_LEVEL=debug', $command);
    }

    public function test_it_reports_docker_as_unavailable_when_the_binary_is_missing(): void
    {
        $server = new DockerServer(new Configuration(), binary: '/definitely/not/docker');

        self::assertFalse($server->isAvailable());
    }
}
