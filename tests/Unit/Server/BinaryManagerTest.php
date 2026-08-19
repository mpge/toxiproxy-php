<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use FilesystemIterator;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\BinaryException;
use Mpge\Toxiproxy\Server\BinaryManager;
use Mpge\Toxiproxy\Server\Platform;
use Mpge\Toxiproxy\Server\Release;
use Mpge\Toxiproxy\Tests\Support\FakeDownloader;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;

final class BinaryManagerTest extends TestCase
{
    private const BODY = 'pretend this is a 7MB Go binary';

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/toxiproxy-php-test-'.bin2hex(random_bytes(6));
    }

    #[After]
    protected function removeHome(): void
    {
        if (! is_dir($this->home)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->home);
    }

    public function test_the_cache_path_is_versioned_and_outside_vendor(): void
    {
        $path = $this->manager()->cachedPath();

        self::assertTrue(str_starts_with($path, $this->home), $path.' should live under '.$this->home);
        self::assertStringContainsString('2.12.0', $path);
        self::assertTrue(str_ends_with($path, Platform::current()->binaryName()), $path);
        self::assertStringNotContainsString('vendor', $path);
    }

    public function test_two_versions_cache_side_by_side(): void
    {
        $a = $this->manager(version: '2.12.0')->cachedPath();
        $b = $this->manager(version: '2.11.0')->cachedPath();

        self::assertNotSame($a, $b);
    }

    public function test_it_downloads_and_verifies_the_checksum(): void
    {
        $downloader = $this->downloader(hash('sha256', self::BODY));
        $manager = $this->manager(downloader: $downloader);

        $path = $manager->install();

        self::assertFileExists($path);
        self::assertSame(self::BODY, file_get_contents($path));
        self::assertCount(1, $downloader->downloads);
        self::assertContains((new Release('2.12.0'))->checksumsUrl(), $downloader->fetched);
    }

    public function test_a_bad_checksum_discards_the_download(): void
    {
        $manager = $this->manager(downloader: $this->downloader(str_repeat('0', 64)));

        try {
            $manager->install();
            self::fail('Expected a BinaryException for the checksum mismatch.');
        } catch (BinaryException $e) {
            self::assertStringContainsString('Checksum mismatch', $e->getMessage());
        }

        // The whole point: a file that failed verification must not be left
        // behind looking installed.
        self::assertFileDoesNotExist($manager->cachedPath());
        self::assertFalse($manager->isInstalled());
    }

    public function test_a_missing_checksum_entry_is_an_error_not_a_silent_pass(): void
    {
        $release = new Release('2.12.0');
        $downloader = (new FakeDownloader())
            ->serve($release->checksumsUrl(), "deadbeef  some-other-asset\n")
            ->serve($release->serverUrl(Platform::current()), self::BODY);

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('publishes no checksum');

        $this->manager(downloader: $downloader)->install();
    }

    public function test_verification_can_be_turned_off_deliberately(): void
    {
        $release = new Release('2.12.0');
        $downloader = (new FakeDownloader())->serve($release->serverUrl(Platform::current()), self::BODY);

        $manager = new BinaryManager(
            $this->config()->withVerifyChecksums(false),
            Platform::current(),
            $downloader,
        );

        self::assertFileExists($manager->install());
        self::assertNotContains($release->checksumsUrl(), $downloader->fetched);
    }

    public function test_an_already_cached_binary_is_not_downloaded_again(): void
    {
        $downloader = $this->downloader(hash('sha256', self::BODY));
        $manager = $this->manager(downloader: $downloader);

        $manager->install();
        $manager->install();

        self::assertCount(1, $downloader->downloads);
    }

    public function test_force_redownloads(): void
    {
        $downloader = $this->downloader(hash('sha256', self::BODY));
        $manager = $this->manager(downloader: $downloader);

        $manager->install();
        $manager->install(force: true);

        self::assertCount(2, $downloader->downloads);
    }

    public function test_progress_is_reported(): void
    {
        $seen = [];

        $this->manager(downloader: $this->downloader(hash('sha256', self::BODY)))
            ->install(onProgress: static function (int $downloaded, int $total) use (&$seen): void {
                $seen[] = [$downloaded, $total];
            });

        self::assertSame([[strlen(self::BODY), strlen(self::BODY)]], $seen);
    }

    public function test_an_explicit_binary_wins_over_everything(): void
    {
        $explicit = $this->home.'/my-own-toxiproxy';
        mkdir($this->home, 0o755, true);
        file_put_contents($explicit, self::BODY);

        $manager = new BinaryManager(
            $this->config()->withBinary($explicit),
            Platform::current(),
            new FakeDownloader(),
        );

        self::assertSame($explicit, $manager->override());
        self::assertSame($explicit, $manager->locate());
        self::assertSame($explicit, $manager->resolve());
        self::assertFalse($manager->isUsingSystemBinary());
    }

    public function test_a_missing_explicit_binary_fails_loudly_instead_of_downloading(): void
    {
        $manager = new BinaryManager(
            $this->config()->withBinary($this->home.'/nope'),
            Platform::current(),
            new FakeDownloader(),
        );

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('No Toxiproxy server binary at');

        $manager->resolve();
    }

    public function test_a_server_already_on_path_is_reused_rather_than_downloaded(): void
    {
        $systemBinary = $this->home.'/system/toxiproxy-server';
        mkdir(dirname($systemBinary), 0o755, true);
        file_put_contents($systemBinary, self::BODY);

        $downloader = new FakeDownloader();

        $manager = new BinaryManager(
            $this->config(),
            new Platform('linux', 'amd64'),
            $downloader,
            new class ($systemBinary) extends ExecutableFinder {
                public function __construct(private readonly string $path)
                {
                }

                /**
                 * @param  array<int, string>  $extraDirs
                 */
                public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
                {
                    return $name === 'toxiproxy-server' ? $this->path : $default;
                }
            },
        );

        self::assertSame($systemBinary, $manager->locate());
        self::assertTrue($manager->isUsingSystemBinary());
        self::assertSame([], $downloader->downloads);
    }

    public function test_auto_install_off_refuses_to_download(): void
    {
        $manager = new BinaryManager(
            $this->config()->withAutoInstall(false),
            Platform::current(),
            new FakeDownloader(),
            $this->finderThatFindsNothing(),
        );

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('TOXIPROXY_AUTO_INSTALL');

        $manager->resolve();
    }

    public function test_it_reports_not_installed_before_any_download(): void
    {
        $manager = new BinaryManager(
            $this->config(),
            Platform::current(),
            new FakeDownloader(),
            $this->finderThatFindsNothing(),
        );

        self::assertFalse($manager->isInstalled());
        self::assertNull($manager->locate());
        self::assertNull($manager->installedVersion());
    }

    private function finderThatFindsNothing(): ExecutableFinder
    {
        return new class () extends ExecutableFinder {
            /**
             * @param  array<int, string>  $extraDirs
             */
            public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
            {
                return $default;
            }
        };
    }

    private function config(string $version = '2.12.0'): Configuration
    {
        return (new Configuration())->withHome($this->home)->withVersion($version);
    }

    private function manager(
        string $version = '2.12.0',
        ?FakeDownloader $downloader = null,
    ): BinaryManager {
        return new BinaryManager(
            $this->config($version),
            Platform::current(),
            $downloader ?? new FakeDownloader(),
            $this->finderThatFindsNothing(),
        );
    }

    private function downloader(string $checksum): FakeDownloader
    {
        $release = new Release('2.12.0');
        $platform = Platform::current();

        return (new FakeDownloader())
            ->serve($release->checksumsUrl(), sprintf("%s  %s\n", $checksum, $platform->assetName()))
            ->serve($release->serverUrl($platform), self::BODY);
    }
}
