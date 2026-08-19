<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

/**
 * The sha256 manifest Shopify publishes alongside every release.
 *
 * Format is one "<hash>  <filename>" per line, as written by sha256sum.
 */
final readonly class Checksums
{
    /**
     * @param  array<string, string>  $hashes  asset name => lowercase sha256
     */
    private function __construct(private array $hashes)
    {
    }

    public static function parse(string $contents): self
    {
        $hashes = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // sha256sum writes two spaces for binary mode and " *" for text
            // mode; accept any run of whitespace so either reads correctly.
            if (preg_match('/^([0-9a-fA-F]{64})\s+\*?(\S.*)$/', $trimmed, $matches) !== 1) {
                continue;
            }

            $hashes[trim($matches[2])] = strtolower($matches[1]);
        }

        return new self($hashes);
    }

    public function for(string $asset): ?string
    {
        return $this->hashes[$asset] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->hashes === [];
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->hashes;
    }
}
