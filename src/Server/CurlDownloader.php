<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use CurlHandle;
use Mpge\Toxiproxy\Exception\BinaryException;

/**
 * The real downloader: curl where available, stream wrappers otherwise.
 *
 * Redirects must be followed here, unlike in the API transport, because GitHub
 * release URLs bounce to an object store.
 */
final class CurlDownloader implements Downloader
{
    public function __construct(
        private readonly float $timeout = 120.0,
        private readonly float $connectTimeout = 10.0,
        private readonly string $userAgent = 'toxiproxy-php',
    ) {
    }

    public function get(string $url): string
    {
        if (! extension_loaded('curl')) {
            return $this->getViaStream($url);
        }

        $handle = $this->handle($url);

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        $body = curl_exec($handle);

        $this->assertNoCurlError($handle, $url);
        $this->assertHttpOk((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $url);

        return is_string($body) ? $body : '';
    }

    public function download(string $url, string $destination, ?callable $onProgress = null): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw BinaryException::unwritableDirectory($directory);
        }

        $temporary = $destination.'.download-'.bin2hex(random_bytes(4));

        try {
            if (extension_loaded('curl')) {
                $this->downloadViaCurl($url, $temporary, $onProgress);
            } else {
                $this->downloadViaStream($url, $temporary);
            }

            // Windows will not overwrite an existing file with rename().
            if (is_file($destination)) {
                @unlink($destination);
            }

            if (! @rename($temporary, $destination)) {
                throw BinaryException::downloadFailed($url, sprintf('could not move the download into %s', $destination));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  (callable(int, int): void)|null  $onProgress
     */
    private function downloadViaCurl(string $url, string $temporary, ?callable $onProgress): void
    {
        $stream = @fopen($temporary, 'wb');

        if ($stream === false) {
            throw BinaryException::downloadFailed($url, sprintf('could not open %s for writing', $temporary));
        }

        $handle = $this->handle($url);

        try {
            curl_setopt($handle, CURLOPT_FILE, $stream);

            if ($onProgress !== null) {
                curl_setopt($handle, CURLOPT_NOPROGRESS, false);
                curl_setopt(
                    $handle,
                    CURLOPT_PROGRESSFUNCTION,
                    static function (CurlHandle $_, int $total, int $downloaded) use ($onProgress): int {
                        $onProgress($downloaded, $total);

                        return 0;
                    },
                );
            }

            curl_exec($handle);

            $this->assertNoCurlError($handle, $url);
            $this->assertHttpOk((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $url);
        } finally {
            // The file handle must be closed before the caller renames the
            // download into place; the curl handle frees itself.
            fclose($stream);
        }
    }

    private function downloadViaStream(string $url, string $temporary): void
    {
        $body = $this->getViaStream($url);

        if (@file_put_contents($temporary, $body) === false) {
            throw BinaryException::downloadFailed($url, sprintf('could not write %s', $temporary));
        }
    }

    private function getViaStream(string $url): string
    {
        if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw BinaryException::downloadFailed(
                $url,
                'neither ext-curl nor allow_url_fopen is available, so nothing can be downloaded. '
                .'Install the binary manually and point TOXIPROXY_BINARY at it.',
            );
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: '.$this->userAgent."\r\nAccept: */*",
                'timeout' => $this->timeout,
                'follow_location' => 1,
                'max_redirects' => 10,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            $error = error_get_last();

            throw BinaryException::downloadFailed($url, $error['message'] ?? 'stream request failed');
        }

        return $body;
    }

    private function handle(string $url): CurlHandle
    {
        $handle = curl_init();

        if (! $handle instanceof CurlHandle) {
            throw BinaryException::downloadFailed($url, 'curl_init() failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->connectTimeout),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_FAILONERROR => false,
        ]);

        return $handle;
    }

    private function assertNoCurlError(CurlHandle $handle, string $url): void
    {
        if (curl_errno($handle) !== 0) {
            throw BinaryException::downloadFailed($url, curl_error($handle));
        }
    }

    private function assertHttpOk(int $status, string $url): void
    {
        if ($status < 200 || $status >= 300) {
            throw BinaryException::downloadFailed($url, sprintf('HTTP %d', $status));
        }
    }
}
