<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\BinaryException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Finds, installs and validates the official toxiproxy-server binary.
 *
 * Resolution order, most explicit first:
 *
 *   1. TOXIPROXY_BINARY, or Configuration::$binary. Used verbatim, no checks
 *      beyond existence, because you said exactly what you wanted.
 *   2. This package's own cache, keyed by version.
 *   3. A toxiproxy-server already on PATH, installed by brew, apt or whatever
 *      else. Reused rather than duplicated; `doctor` tells you when this is
 *      what is in play, since its version is outside our control.
 *   4. Download from GitHub Releases, if auto-install is on.
 *
 * The cache deliberately lives outside vendor/. That directory is disposable
 * and rebuilt constantly in CI, and a multi-megabyte binary per project is
 * waste when one per machine will do.
 */
final class BinaryManager
{
    private ?string $resolved = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly ?Platform $platform = null,
        private readonly ?Downloader $downloader = null,
        private readonly ?ExecutableFinder $finder = null,
    ) {
    }

    public static function create(?Configuration $config = null): self
    {
        return new self($config ?? Configuration::fromEnvironment(), Platform::current());
    }

    public function platform(): Platform
    {
        return $this->platform ?? Platform::current();
    }

    public function release(): Release
    {
        return $this->config->wantsLatest()
            ? Release::latest($this->downloader())
            : new Release($this->config->version);
    }

    /**
     * Where this package would cache the binary for the configured version.
     */
    public function cachedPath(?Release $release = null): string
    {
        $release ??= new Release(
            $this->config->wantsLatest() ? Release::DEFAULT_VERSION : $this->config->version,
        );

        return implode(DIRECTORY_SEPARATOR, [
            $this->config->homeDirectory($this->platform()),
            'bin',
            $release->version,
            $this->platform()->binaryName(),
        ]);
    }

    /**
     * An explicitly configured binary, if any.
     */
    public function override(): ?string
    {
        $binary = $this->config->binary;

        return $binary !== null && trim($binary) !== '' ? $binary : null;
    }

    /**
     * A toxiproxy-server already installed on this machine, outside our cache.
     */
    public function onPath(): ?string
    {
        $finder = $this->finder ?? new ExecutableFinder();

        foreach ($this->platform()->executableCandidates() as $candidate) {
            $found = $finder->find($candidate);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The binary this configuration would use right now, without installing.
     */
    public function locate(): ?string
    {
        $override = $this->override();

        if ($override !== null) {
            return is_file($override) ? $override : null;
        }

        $cached = $this->cachedPath();

        if (is_file($cached)) {
            return $cached;
        }

        return $this->onPath();
    }

    public function isInstalled(): bool
    {
        return $this->locate() !== null;
    }

    /**
     * Resolve a usable binary, downloading it first if that is allowed.
     *
     * @throws BinaryException
     */
    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $override = $this->override();

        if ($override !== null) {
            if (! is_file($override)) {
                throw BinaryException::notInstalled($override);
            }

            return $this->resolved = $this->makeExecutable($override);
        }

        $located = $this->locate();

        if ($located !== null) {
            return $this->resolved = $this->makeExecutable($located);
        }

        if (! $this->config->autoInstall) {
            throw BinaryException::notInstalled($this->cachedPath());
        }

        return $this->resolved = $this->install();
    }

    /**
     * Download the binary for the configured version into the cache.
     *
     * Already present and not forced, this is a no-op, so it is safe to call on
     * every test run.
     *
     * @param  (callable(int $downloaded, int $total): void)|null  $onProgress
     */
    public function install(bool $force = false, ?callable $onProgress = null): string
    {
        $release = $this->release();
        $platform = $this->platform();
        $destination = $this->cachedPath($release);

        if (! $force && is_file($destination)) {
            return $this->makeExecutable($destination);
        }

        $asset = $platform->assetName();
        $url = $release->serverUrl($platform);
        $downloader = $this->downloader();

        $expected = $this->config->verifyChecksums
            ? $this->expectedChecksum($release, $asset, $downloader)
            : null;

        $downloader->download($url, $destination, $onProgress);

        if ($expected !== null) {
            $actual = hash_file('sha256', $destination);

            if ($actual !== $expected) {
                @unlink($destination);

                throw BinaryException::checksumMismatch($asset, $expected, (string) $actual);
            }
        }

        $this->resolved = null;

        return $this->makeExecutable($destination);
    }

    /**
     * Remove the cached binary for the configured version.
     */
    public function uninstall(): bool
    {
        $path = $this->cachedPath();
        $this->resolved = null;

        return is_file($path) && @unlink($path);
    }

    /**
     * Ask the binary what version it is.
     *
     * Returns null rather than throwing, because a corrupt or foreign binary is
     * something `doctor` should report, not something that should explode.
     */
    public function installedVersion(?string $binary = null): ?string
    {
        $path = $binary ?? $this->locate();

        if ($path === null) {
            return null;
        }

        $process = new Process([$path, '-version']);
        $process->setTimeout(10.0);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        // Upstream prints "toxiproxy-server version 2.12.0".
        if (preg_match('/(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)/', $process->getOutput(), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * True when the binary in play was found on PATH rather than installed here.
     */
    public function isUsingSystemBinary(): bool
    {
        if ($this->override() !== null) {
            return false;
        }

        return ! is_file($this->cachedPath()) && $this->onPath() !== null;
    }

    private function expectedChecksum(Release $release, string $asset, Downloader $downloader): string
    {
        try {
            $checksums = Checksums::parse($downloader->get($release->checksumsUrl()));
        } catch (BinaryException $e) {
            throw BinaryException::checksumUnavailable($asset, $e->getMessage());
        }

        $hash = $checksums->for($asset);

        if ($hash === null) {
            throw BinaryException::checksumUnavailable(
                $asset,
                sprintf('release %s publishes no checksum for it', $release->tag()),
            );
        }

        return $hash;
    }

    private function makeExecutable(string $path): string
    {
        if ($this->platform()->isWindows()) {
            return $path;
        }

        if (is_executable($path)) {
            return $path;
        }

        if (! @chmod($path, 0o755)) {
            throw BinaryException::notExecutable($path);
        }

        return $path;
    }

    private function downloader(): Downloader
    {
        return $this->downloader ?? new CurlDownloader();
    }
}
