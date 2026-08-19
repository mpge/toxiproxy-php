<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Support;

use Mpge\Toxiproxy\Exception\BinaryException;
use Mpge\Toxiproxy\Server\Downloader;

/**
 * A Downloader with a canned internet, so the install path can be tested
 * without leaving the machine.
 */
final class FakeDownloader implements Downloader
{
    /** @var list<string> */
    public array $fetched = [];

    /** @var list<array{url: string, destination: string}> */
    public array $downloads = [];

    /** @var array<string, string> */
    private array $bodies = [];

    /** @var array<string, string> */
    private array $failures = [];

    public function serve(string $url, string $body): self
    {
        $this->bodies[$url] = $body;

        return $this;
    }

    public function fail(string $url, string $reason): self
    {
        $this->failures[$url] = $reason;

        return $this;
    }

    public function get(string $url): string
    {
        $this->fetched[] = $url;

        if (isset($this->failures[$url])) {
            throw BinaryException::downloadFailed($url, $this->failures[$url]);
        }

        return $this->bodies[$url]
            ?? throw BinaryException::downloadFailed($url, 'HTTP 404');
    }

    public function download(string $url, string $destination, ?callable $onProgress = null): void
    {
        $body = $this->get($url);

        $this->downloads[] = ['url' => $url, 'destination' => $destination];

        $directory = dirname($destination);

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        file_put_contents($destination, $body);

        if ($onProgress !== null) {
            $onProgress(strlen($body), strlen($body));
        }
    }
}
