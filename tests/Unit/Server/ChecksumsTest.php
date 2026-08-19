<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use Mpge\Toxiproxy\Server\Checksums;
use PHPUnit\Framework\TestCase;

final class ChecksumsTest extends TestCase
{
    /**
     * Trimmed from the real v2.12.0 checksums.txt, including its exact spacing.
     */
    private const SAMPLE = <<<'TXT'
    00f328635c647b7caa8292852245c7c70dd480d3114b25a2b43145a11651abfb  toxiproxy_2.12.0_solaris_amd64.tar.gz
    556d891134a3c582dc1e1a3f7335fd55142e5965769855a00b944e13e48302fc  toxiproxy-server-linux-amd64
    53e770c1c3035b5a9f1bc629fce537db1f95f62b26f4ebe6e756afd701cf077c  toxiproxy-server-linux-arm64
    TXT;

    public function test_it_parses_the_upstream_checksums_format(): void
    {
        $checksums = Checksums::parse(self::SAMPLE);

        self::assertSame(
            '556d891134a3c582dc1e1a3f7335fd55142e5965769855a00b944e13e48302fc',
            $checksums->for('toxiproxy-server-linux-amd64'),
        );

        self::assertSame(
            '53e770c1c3035b5a9f1bc629fce537db1f95f62b26f4ebe6e756afd701cf077c',
            $checksums->for('toxiproxy-server-linux-arm64'),
        );
    }

    public function test_it_returns_null_for_an_asset_that_is_not_listed(): void
    {
        self::assertNull(Checksums::parse(self::SAMPLE)->for('toxiproxy-server-windows-arm64.exe'));
    }

    public function test_it_accepts_the_binary_mode_star_prefix(): void
    {
        $checksums = Checksums::parse(
            'abc123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd *toxiproxy-server-linux-amd64',
        );

        self::assertSame(
            'abc123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd',
            $checksums->for('toxiproxy-server-linux-amd64'),
        );
    }

    public function test_it_lowercases_hashes_so_comparison_is_stable(): void
    {
        $checksums = Checksums::parse(
            'ABC123ABC123ABC123ABC123ABC123ABC123ABC123ABC123ABC123ABC123ABCD  toxiproxy-server-linux-amd64',
        );

        self::assertSame(
            'abc123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd',
            $checksums->for('toxiproxy-server-linux-amd64'),
        );
    }

    public function test_it_handles_crlf_line_endings(): void
    {
        $checksums = Checksums::parse(str_replace("\n", "\r\n", self::SAMPLE));

        self::assertNotNull($checksums->for('toxiproxy-server-linux-amd64'));
        self::assertNotNull($checksums->for('toxiproxy-server-linux-arm64'));
    }

    public function test_it_skips_blank_lines_comments_and_malformed_rows(): void
    {
        $checksums = Checksums::parse(<<<'TXT'
        # a comment

        not-a-hash  some-file
        556d891134a3c582dc1e1a3f7335fd55142e5965769855a00b944e13e48302fc  toxiproxy-server-linux-amd64
        TXT);

        self::assertSame(['toxiproxy-server-linux-amd64'], array_keys($checksums->all()));
    }

    public function test_an_empty_manifest_is_reported_as_empty(): void
    {
        self::assertTrue(Checksums::parse('')->isEmpty());
        self::assertFalse(Checksums::parse(self::SAMPLE)->isEmpty());
    }
}
