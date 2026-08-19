<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown for problems acquiring or validating the toxiproxy-server binary.
 */
class BinaryException extends RuntimeException implements ToxiproxyException
{
    public static function downloadFailed(string $url, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Failed to download %s: %s', $url, $reason), 0, $previous);
    }

    public static function checksumMismatch(string $asset, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Checksum mismatch for %s. Expected sha256 %s but the downloaded file hashes to %s. '
            .'The download was discarded.',
            $asset,
            $expected,
            $actual,
        ));
    }

    public static function checksumUnavailable(string $asset, string $reason): self
    {
        return new self(sprintf(
            'Could not verify %s because its checksum could not be fetched: %s. '
            .'Re-run with --no-verify to install without checksum verification.',
            $asset,
            $reason,
        ));
    }

    public static function notInstalled(string $path): self
    {
        return new self(sprintf(
            'No Toxiproxy server binary at %s. Run "vendor/bin/toxiproxy-php install", '
            .'or enable automatic installation with TOXIPROXY_AUTO_INSTALL=1.',
            $path,
        ));
    }

    public static function notExecutable(string $path): self
    {
        return new self(sprintf('The Toxiproxy binary at %s exists but is not executable.', $path));
    }

    public static function unwritableDirectory(string $path): self
    {
        return new self(sprintf(
            'Cannot write to the Toxiproxy binary cache at %s. '
            .'Set TOXIPROXY_HOME to a writable directory.',
            $path,
        ));
    }
}
