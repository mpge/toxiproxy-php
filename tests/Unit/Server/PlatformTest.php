<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use Mpge\Toxiproxy\Exception\UnsupportedPlatformException;
use Mpge\Toxiproxy\Server\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function detectionCases(): iterable
    {
        yield 'linux x86_64' => ['Linux', 'x86_64', '', 'linux/amd64'];
        yield 'linux aarch64' => ['Linux', 'aarch64', '', 'linux/arm64'];
        yield 'linux arm64' => ['Linux', 'arm64', '', 'linux/arm64'];
        yield 'macos intel' => ['Darwin', 'x86_64', 'Darwin', 'darwin/amd64'];
        yield 'macos apple silicon' => ['Darwin', 'arm64', 'Darwin', 'darwin/arm64'];
        yield 'windows' => ['Windows', 'AMD64', 'Windows NT', 'windows/amd64'];
        yield 'freebsd' => ['BSD', 'amd64', 'FreeBSD', 'freebsd/amd64'];
        yield 'openbsd' => ['BSD', 'amd64', 'OpenBSD', 'openbsd/amd64'];
        yield 'netbsd' => ['BSD', 'amd64', 'NetBSD', 'netbsd/amd64'];
        yield 'solaris' => ['Solaris', 'i86pc', 'SunOS', 'solaris/i86pc'];
        yield 'raspberry pi 32 bit' => ['Linux', 'armv7l', '', 'linux/armv7'];
        yield 'legacy 32 bit intel' => ['Linux', 'i686', '', 'linux/386'];
    }

    #[DataProvider('detectionCases')]
    public function test_it_maps_php_uname_output_onto_go_platform_names(
        string $family,
        string $machine,
        string $sysname,
        string $expected,
    ): void {
        self::assertSame($expected, Platform::detect($family, $machine, $sysname)->toString());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function assetCases(): iterable
    {
        yield 'linux amd64' => ['linux', 'amd64', 'toxiproxy-server-linux-amd64'];
        yield 'linux arm64' => ['linux', 'arm64', 'toxiproxy-server-linux-arm64'];
        yield 'darwin arm64' => ['darwin', 'arm64', 'toxiproxy-server-darwin-arm64'];
        yield 'windows gets .exe' => ['windows', 'amd64', 'toxiproxy-server-windows-amd64.exe'];
        yield 'freebsd arm64' => ['freebsd', 'arm64', 'toxiproxy-server-freebsd-arm64'];
    }

    #[DataProvider('assetCases')]
    public function test_it_names_the_release_asset_upstream_publishes(
        string $os,
        string $architecture,
        string $expected,
    ): void {
        self::assertSame($expected, (new Platform($os, $architecture))->assetName());
    }

    /**
     * Every pair here is genuinely absent from the release page. Claiming to
     * support one would produce a 404 at install time instead of a clear error.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedCases(): iterable
    {
        yield 'no windows arm64 build' => ['windows', 'arm64'];
        yield 'no linux 386 build' => ['linux', '386'];
        yield 'no linux arm build' => ['linux', 'armv7'];
        yield 'no netbsd arm64 build' => ['netbsd', 'arm64'];
        yield 'no dragonfly build at all' => ['dragonfly', 'amd64'];
    }

    #[DataProvider('unsupportedCases')]
    public function test_it_refuses_platforms_with_no_published_binary(string $os, string $architecture): void
    {
        $platform = new Platform($os, $architecture);

        self::assertFalse($platform->isSupported());

        $this->expectException(UnsupportedPlatformException::class);
        $this->expectExceptionMessage($os.'/'.$architecture);

        $platform->assetName();
    }

    public function test_the_unsupported_message_points_somewhere_useful(): void
    {
        try {
            (new Platform('windows', 'arm64'))->assetName();
            self::fail('Expected an UnsupportedPlatformException.');
        } catch (UnsupportedPlatformException $e) {
            self::assertStringContainsString('TOXIPROXY_BINARY', $e->getMessage());
            self::assertStringContainsString('TOXIPROXY_HOST', $e->getMessage());
        }
    }

    public function test_it_names_the_cached_binary_per_platform(): void
    {
        self::assertSame('toxiproxy-server', (new Platform('linux', 'amd64'))->binaryName());
        self::assertSame('toxiproxy-server.exe', (new Platform('windows', 'amd64'))->binaryName());
    }

    public function test_the_current_platform_is_detectable(): void
    {
        $platform = Platform::current();

        self::assertNotSame('', $platform->os);
        self::assertNotSame('', $platform->architecture);
    }

    public function test_supported_targets_lists_only_published_pairs(): void
    {
        $targets = Platform::supportedTargets();

        self::assertContains('linux/amd64', $targets);
        self::assertContains('darwin/arm64', $targets);
        self::assertContains('windows/amd64', $targets);
        self::assertNotContains('windows/arm64', $targets);
        self::assertNotContains('linux/386', $targets);
    }
}
