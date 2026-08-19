<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Exception\UnsupportedPlatformException;

/**
 * Which Toxiproxy release binary this machine needs.
 *
 * The OS and architecture names are Go's, because they are what appears in the
 * release asset filenames: toxiproxy-server-linux-amd64 and friends.
 *
 * Detection is pure: pass in the three uname-ish values and get a Platform
 * back, so every branch is unit testable from any host.
 */
final readonly class Platform
{
    /**
     * The os/arch pairs Shopify actually publishes a server binary for, taken
     * from the release assets rather than from Go's full platform matrix.
     *
     * @var array<string, list<string>>
     */
    private const PUBLISHED = [
        'darwin' => ['amd64', 'arm64'],
        'linux' => ['amd64', 'arm64'],
        'windows' => ['amd64'],
        'freebsd' => ['amd64', 'arm64'],
        'openbsd' => ['amd64', 'arm64'],
        'netbsd' => ['amd64'],
        'solaris' => ['amd64'],
    ];

    public function __construct(
        public string $os,
        public string $architecture,
    ) {
    }

    public static function current(): self
    {
        return self::detect(PHP_OS_FAMILY, php_uname('m'), php_uname('s'));
    }

    /**
     * @param  string  $family   PHP_OS_FAMILY: Windows, Darwin, Linux, BSD, Solaris
     * @param  string  $machine  php_uname('m'): x86_64, arm64, aarch64, ...
     * @param  string  $sysname  php_uname('s'), which is the only way to tell the BSDs apart
     */
    public static function detect(string $family, string $machine, string $sysname = ''): self
    {
        return new self(
            self::normaliseOs($family, $sysname),
            self::normaliseArchitecture($machine),
        );
    }

    public function isWindows(): bool
    {
        return $this->os === 'windows';
    }

    public function isMacOs(): bool
    {
        return $this->os === 'darwin';
    }

    public function isSupported(): bool
    {
        return in_array($this->architecture, self::PUBLISHED[$this->os] ?? [], true);
    }

    /**
     * The release asset that carries the server for this platform.
     *
     * @throws UnsupportedPlatformException
     */
    public function assetName(): string
    {
        if (! $this->isSupported()) {
            throw UnsupportedPlatformException::for($this->os, $this->architecture);
        }

        return sprintf(
            'toxiproxy-server-%s-%s%s',
            $this->os,
            $this->architecture,
            $this->isWindows() ? '.exe' : '',
        );
    }

    /**
     * What the binary is called once cached locally.
     */
    public function binaryName(): string
    {
        return $this->isWindows() ? 'toxiproxy-server.exe' : 'toxiproxy-server';
    }

    /**
     * The name to look for when scanning PATH for an already-installed server.
     *
     * @return list<string>
     */
    public function executableCandidates(): array
    {
        return $this->isWindows()
            ? ['toxiproxy-server.exe', 'toxiproxy-server']
            : ['toxiproxy-server'];
    }

    /**
     * @return list<string>
     */
    public static function supportedTargets(): array
    {
        $targets = [];

        foreach (self::PUBLISHED as $os => $architectures) {
            foreach ($architectures as $architecture) {
                $targets[] = $os.'/'.$architecture;
            }
        }

        return $targets;
    }

    public function toString(): string
    {
        return $this->os.'/'.$this->architecture;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function normaliseOs(string $family, string $sysname): string
    {
        return match (strtolower($family)) {
            'windows' => 'windows',
            'darwin' => 'darwin',
            'linux' => 'linux',
            'solaris' => 'solaris',
            // PHP lumps every BSD under one family, so the only way to pick the
            // right asset is the kernel name.
            'bsd' => match (true) {
                str_contains(strtolower($sysname), 'openbsd') => 'openbsd',
                str_contains(strtolower($sysname), 'netbsd') => 'netbsd',
                str_contains(strtolower($sysname), 'dragonfly') => 'dragonfly',
                default => 'freebsd',
            },
            default => strtolower($family),
        };
    }

    private static function normaliseArchitecture(string $machine): string
    {
        return match (strtolower(trim($machine))) {
            'x86_64', 'amd64', 'x64' => 'amd64',
            'arm64', 'aarch64', 'armv8', 'armv8l' => 'arm64',
            'i386', 'i486', 'i586', 'i686', 'x86' => '386',
            'armv6l', 'armv6' => 'armv6',
            'armv7l', 'armv7' => 'armv7',
            default => strtolower(trim($machine)),
        };
    }
}
