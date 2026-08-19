<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use JsonException;
use Mpge\Toxiproxy\Exception\BinaryException;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;

/**
 * A single upstream Toxiproxy release, and the URLs that belong to it.
 *
 * Pure string work, so URL generation is unit tested without a network.
 */
final readonly class Release
{
    /**
     * The version installed when nothing else is configured.
     *
     * Pinned rather than tracking "latest" on purpose: a test suite that
     * silently changes its proxy server on someone else's release schedule is a
     * flake waiting to happen. Set TOXIPROXY_VERSION=latest to opt out.
     */
    public const DEFAULT_VERSION = '2.12.0';

    public const REPOSITORY = 'Shopify/toxiproxy';

    public const LATEST = 'latest';

    public string $version;

    public function __construct(string $version)
    {
        $normalised = ltrim(trim($version), 'vV');

        if ($normalised === '') {
            throw new InvalidArgumentException('A Toxiproxy version cannot be empty.');
        }

        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $normalised) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Version "%s" does not look like a Toxiproxy release. Expected something like "2.12.0", or "latest".',
                $version,
            ));
        }

        $this->version = $normalised;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_VERSION);
    }

    /**
     * Turn a configured version string into a concrete release, querying GitHub
     * only when the caller asked for "latest".
     */
    public static function resolve(?string $version, ?Downloader $downloader = null): self
    {
        $requested = trim((string) $version);

        if ($requested === '') {
            return self::default();
        }

        if (strtolower($requested) === self::LATEST) {
            return self::latest($downloader ?? new CurlDownloader());
        }

        return new self($requested);
    }

    public static function latest(Downloader $downloader): self
    {
        $url = sprintf('https://api.github.com/repos/%s/releases/latest', self::REPOSITORY);
        $body = $downloader->get($url);

        try {
            /** @var mixed $payload */
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw BinaryException::downloadFailed($url, 'the release metadata was not valid JSON', $e);
        }

        if (! is_array($payload) || ! isset($payload['tag_name']) || ! is_string($payload['tag_name'])) {
            throw BinaryException::downloadFailed($url, 'the release metadata carried no tag_name');
        }

        return new self($payload['tag_name']);
    }

    public function tag(): string
    {
        return 'v'.$this->version;
    }

    public function downloadUrl(string $asset): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/%s/%s',
            self::REPOSITORY,
            $this->tag(),
            $asset,
        );
    }

    public function serverUrl(Platform $platform): string
    {
        return $this->downloadUrl($platform->assetName());
    }

    public function checksumsUrl(): string
    {
        return $this->downloadUrl('checksums.txt');
    }

    public function releasePage(): string
    {
        return sprintf('https://github.com/%s/releases/tag/%s', self::REPOSITORY, $this->tag());
    }

    public function equals(self $other): bool
    {
        return $this->version === $other->version;
    }

    public function __toString(): string
    {
        return $this->version;
    }
}
