<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use Mpge\Toxiproxy\Exception\BinaryException;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Server\Platform;
use Mpge\Toxiproxy\Server\Release;
use Mpge\Toxiproxy\Tests\Support\FakeDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private const LATEST_URL = 'https://api.github.com/repos/Shopify/toxiproxy/releases/latest';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function versionCases(): iterable
    {
        yield 'plain' => ['2.12.0', '2.12.0'];
        yield 'v prefixed' => ['v2.12.0', '2.12.0'];
        yield 'capital V prefixed' => ['V2.12.0', '2.12.0'];
        yield 'whitespace' => ['  2.12.0  ', '2.12.0'];
        yield 'prerelease' => ['2.13.0-rc.1', '2.13.0-rc.1'];
    }

    #[DataProvider('versionCases')]
    public function test_it_normalises_version_strings(string $input, string $expected): void
    {
        self::assertSame($expected, (new Release($input))->version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'not a version' => ['stable'];
        yield 'incomplete' => ['2.12'];
        yield 'a path' => ['../../etc/passwd'];
        yield 'injection attempt' => ['2.12.0/../../../evil'];
    }

    /**
     * A version string ends up in a download URL, so anything that is not a
     * version has to be rejected before it gets there.
     */
    #[DataProvider('invalidVersions')]
    public function test_it_rejects_anything_that_is_not_a_version(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Release($version);
    }

    public function test_it_builds_the_upstream_download_urls(): void
    {
        $release = new Release('2.12.0');

        self::assertSame('v2.12.0', $release->tag());

        self::assertSame(
            'https://github.com/Shopify/toxiproxy/releases/download/v2.12.0/toxiproxy-server-linux-amd64',
            $release->serverUrl(new Platform('linux', 'amd64')),
        );

        self::assertSame(
            'https://github.com/Shopify/toxiproxy/releases/download/v2.12.0/toxiproxy-server-windows-amd64.exe',
            $release->serverUrl(new Platform('windows', 'amd64')),
        );

        self::assertSame(
            'https://github.com/Shopify/toxiproxy/releases/download/v2.12.0/checksums.txt',
            $release->checksumsUrl(),
        );
    }

    public function test_the_default_version_is_pinned_not_floating(): void
    {
        self::assertSame(Release::DEFAULT_VERSION, Release::default()->version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Release::DEFAULT_VERSION);
    }

    public function test_resolve_falls_back_to_the_pinned_default(): void
    {
        self::assertSame(Release::DEFAULT_VERSION, Release::resolve(null)->version);
        self::assertSame(Release::DEFAULT_VERSION, Release::resolve('')->version);
        self::assertSame(Release::DEFAULT_VERSION, Release::resolve('   ')->version);
    }

    public function test_resolve_passes_an_explicit_version_through_without_a_network_call(): void
    {
        $downloader = new FakeDownloader();

        self::assertSame('2.9.0', Release::resolve('2.9.0', $downloader)->version);
        self::assertSame([], $downloader->fetched);
    }

    public function test_latest_reads_the_tag_from_the_github_api(): void
    {
        $downloader = (new FakeDownloader())->serve(
            self::LATEST_URL,
            (string) json_encode(['tag_name' => 'v2.13.1', 'name' => 'v2.13.1']),
        );

        self::assertSame('2.13.1', Release::resolve('latest', $downloader)->version);
        self::assertSame([self::LATEST_URL], $downloader->fetched);
    }

    public function test_latest_is_case_insensitive(): void
    {
        $downloader = (new FakeDownloader())->serve(
            self::LATEST_URL,
            (string) json_encode(['tag_name' => 'v2.13.1']),
        );

        self::assertSame('2.13.1', Release::resolve('LATEST', $downloader)->version);
    }

    public function test_it_reports_a_useful_error_when_the_release_metadata_is_junk(): void
    {
        $downloader = (new FakeDownloader())->serve(self::LATEST_URL, 'not json at all');

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('not valid JSON');

        Release::latest($downloader);
    }

    public function test_it_reports_a_useful_error_when_the_release_has_no_tag(): void
    {
        $downloader = (new FakeDownloader())->serve(self::LATEST_URL, (string) json_encode(['message' => 'Not Found']));

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('no tag_name');

        Release::latest($downloader);
    }

    public function test_releases_compare_by_version(): void
    {
        self::assertTrue((new Release('2.12.0'))->equals(new Release('v2.12.0')));
        self::assertFalse((new Release('2.12.0'))->equals(new Release('2.11.0')));
    }
}
