<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Exception\BinaryException;

/**
 * Fetching bytes from the internet, kept behind an interface so the whole
 * install path can be exercised in unit tests without touching the network.
 */
interface Downloader
{
    /**
     * Fetch a small resource into memory. Used for checksums and release metadata.
     *
     * @throws BinaryException
     */
    public function get(string $url): string;

    /**
     * Stream a large resource to disk.
     *
     * Implementations must write to a temporary file and move it into place
     * only on success, so an interrupted download never leaves a truncated
     * binary that looks installed.
     *
     * @param  (callable(int $downloaded, int $total): void)|null  $onProgress
     *
     * @throws BinaryException
     */
    public function download(string $url, string $destination, ?callable $onProgress = null): void;
}
