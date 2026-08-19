<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Proxy\Address;
use Mpge\Toxiproxy\Server\Platform;
use Mpge\Toxiproxy\Server\Release;
use Mpge\Toxiproxy\Support\Environment;

/**
 * Every knob this package has, in one immutable object.
 *
 * Defaults are chosen so that `Toxiproxy::start()` works on a clean machine
 * with no configuration at all. Environment variables override the defaults;
 * explicit constructor arguments override everything.
 */
final readonly class Configuration
{
    public const ENV_HOST = 'TOXIPROXY_HOST';

    public const ENV_PORT = 'TOXIPROXY_PORT';

    public const ENV_URL = 'TOXIPROXY_URL';

    public const ENV_VERSION = 'TOXIPROXY_VERSION';

    public const ENV_BINARY = 'TOXIPROXY_BINARY';

    public const ENV_AUTO_INSTALL = 'TOXIPROXY_AUTO_INSTALL';

    public const ENV_LOG_LEVEL = 'TOXIPROXY_LOG_LEVEL';

    public const ENV_HOME = 'TOXIPROXY_HOME';

    public const ENV_PROXY_HOST = 'TOXIPROXY_PROXY_HOST';

    public const ENV_START_TIMEOUT = 'TOXIPROXY_START_TIMEOUT';

    public const ENV_VERIFY_CHECKSUMS = 'TOXIPROXY_VERIFY_CHECKSUMS';

    public const ENV_DEBUG = 'TOXIPROXY_DEBUG';

    /**
     * @param  string       $host           where the Toxiproxy API listens
     * @param  int          $port           the API port; 8474 is upstream's default
     * @param  string|null  $binary         an explicit server binary, bypassing the cache entirely
     * @param  string       $version        the release to install, or "latest"
     * @param  bool         $autoInstall    download the binary on demand rather than erroring
     * @param  string|null  $home           where downloaded binaries are cached
     * @param  string       $logLevel       passed to the server as LOG_LEVEL (zerolog levels)
     * @param  float        $startTimeout   seconds to wait for the API to answer after spawning
     * @param  string       $proxyHost      the interface new proxies listen on
     * @param  bool         $verifyChecksums verify downloads against the release checksums file
     * @param  bool         $debug          stream the server's log to the console
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = ToxiproxyClient::DEFAULT_PORT,
        public ?string $binary = null,
        public string $version = Release::DEFAULT_VERSION,
        public bool $autoInstall = true,
        public ?string $home = null,
        public string $logLevel = 'info',
        public float $startTimeout = 15.0,
        public string $proxyHost = Address::DEFAULT_HOST,
        public bool $verifyChecksums = true,
        public bool $debug = false,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('Toxiproxy API port %d is outside 1-65535.', $port));
        }

        if ($startTimeout <= 0) {
            throw new InvalidArgumentException('The start timeout must be greater than zero seconds.');
        }
    }

    /**
     * Build configuration from environment variables.
     *
     * TOXIPROXY_URL is a convenience for CI, where Toxiproxy often runs as a
     * named service: setting it overrides host and port together.
     */
    public static function fromEnvironment(?Environment $environment = null): self
    {
        $env = $environment ?? Environment::real();
        $defaults = new self();

        $host = $env->get(self::ENV_HOST, $defaults->host) ?? $defaults->host;
        $port = $env->integer(self::ENV_PORT, $defaults->port) ?? $defaults->port;

        $url = $env->get(self::ENV_URL);

        if ($url !== null) {
            $parsed = self::parseUrl($url);
            $host = $parsed->host;
            $port = $parsed->port;
        }

        return new self(
            host: $host,
            port: $port,
            binary: $env->get(self::ENV_BINARY),
            version: $env->get(self::ENV_VERSION, $defaults->version) ?? $defaults->version,
            autoInstall: $env->boolean(self::ENV_AUTO_INSTALL, $defaults->autoInstall),
            home: $env->get(self::ENV_HOME),
            logLevel: $env->get(self::ENV_LOG_LEVEL, $defaults->logLevel) ?? $defaults->logLevel,
            startTimeout: $env->float(self::ENV_START_TIMEOUT, $defaults->startTimeout),
            proxyHost: $env->get(self::ENV_PROXY_HOST, $defaults->proxyHost) ?? $defaults->proxyHost,
            verifyChecksums: $env->boolean(self::ENV_VERIFY_CHECKSUMS, $defaults->verifyChecksums),
            debug: $env->boolean(self::ENV_DEBUG, $defaults->debug),
        );
    }

    public function apiUrl(): string
    {
        return 'http://'.(new Address($this->host, $this->port))->toString();
    }

    public function release(): Release
    {
        return new Release($this->version === Release::LATEST ? Release::DEFAULT_VERSION : $this->version);
    }

    public function wantsLatest(): bool
    {
        return strtolower($this->version) === Release::LATEST;
    }

    /**
     * Where downloaded binaries live.
     *
     * Kept out of the vendor directory on purpose: vendor/ is disposable and
     * frequently rebuilt in CI, and a 7 MB binary per project per install is
     * waste. One cache per machine, shared across every project.
     */
    public function homeDirectory(?Platform $platform = null): string
    {
        if ($this->home !== null && trim($this->home) !== '') {
            return rtrim($this->home, '/\\');
        }

        return self::defaultHome($platform ?? Platform::current());
    }

    public static function defaultHome(?Platform $platform = null): string
    {
        $platform ??= Platform::current();
        $env = Environment::real();

        if ($platform->isWindows()) {
            $base = $env->get('LOCALAPPDATA') ?? $env->get('APPDATA') ?? sys_get_temp_dir();

            return rtrim($base, '/\\').'\\toxiproxy-php';
        }

        $home = $env->get('HOME') ?? sys_get_temp_dir();

        if ($platform->isMacOs()) {
            return rtrim($home, '/').'/Library/Caches/toxiproxy-php';
        }

        $xdg = $env->get('XDG_CACHE_HOME');

        return $xdg !== null
            ? rtrim($xdg, '/').'/toxiproxy-php'
            : rtrim($home, '/').'/.cache/toxiproxy-php';
    }

    // -------------------------------------------------------------- builders

    public function withHost(string $host): self
    {
        return $this->copy(host: $host);
    }

    public function withPort(int $port): self
    {
        return $this->copy(port: $port);
    }

    public function withVersion(string $version): self
    {
        return $this->copy(version: $version);
    }

    /**
     * Passing null clears the override and returns to the cached binary.
     */
    public function withBinary(?string $binary): self
    {
        return new self(
            $this->host,
            $this->port,
            $binary,
            $this->version,
            $this->autoInstall,
            $this->home,
            $this->logLevel,
            $this->startTimeout,
            $this->proxyHost,
            $this->verifyChecksums,
            $this->debug,
        );
    }

    public function withAutoInstall(bool $autoInstall): self
    {
        return $this->copy(autoInstall: $autoInstall);
    }

    /**
     * Passing null clears the override and returns to the per-machine default.
     */
    public function withHome(?string $home): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->binary,
            $this->version,
            $this->autoInstall,
            $home,
            $this->logLevel,
            $this->startTimeout,
            $this->proxyHost,
            $this->verifyChecksums,
            $this->debug,
        );
    }

    public function withLogLevel(string $logLevel): self
    {
        return $this->copy(logLevel: $logLevel);
    }

    public function withStartTimeout(float $seconds): self
    {
        return $this->copy(startTimeout: $seconds);
    }

    public function withProxyHost(string $proxyHost): self
    {
        return $this->copy(proxyHost: $proxyHost);
    }

    public function withVerifyChecksums(bool $verify): self
    {
        return $this->copy(verifyChecksums: $verify);
    }

    public function withDebug(bool $debug): self
    {
        return $this->copy(debug: $debug);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'binary' => $this->binary,
            'version' => $this->version,
            'auto_install' => $this->autoInstall,
            'home' => $this->home,
            'log_level' => $this->logLevel,
            'start_timeout' => $this->startTimeout,
            'proxy_host' => $this->proxyHost,
            'verify_checksums' => $this->verifyChecksums,
            'debug' => $this->debug,
        ];
    }

    private function copy(
        ?string $host = null,
        ?int $port = null,
        ?string $binary = null,
        ?string $version = null,
        ?bool $autoInstall = null,
        ?string $home = null,
        ?string $logLevel = null,
        ?float $startTimeout = null,
        ?string $proxyHost = null,
        ?bool $verifyChecksums = null,
        ?bool $debug = null,
    ): self {
        return new self(
            host: $host ?? $this->host,
            port: $port ?? $this->port,
            binary: $binary ?? $this->binary,
            version: $version ?? $this->version,
            autoInstall: $autoInstall ?? $this->autoInstall,
            home: $home ?? $this->home,
            logLevel: $logLevel ?? $this->logLevel,
            startTimeout: $startTimeout ?? $this->startTimeout,
            proxyHost: $proxyHost ?? $this->proxyHost,
            verifyChecksums: $verifyChecksums ?? $this->verifyChecksums,
            debug: $debug ?? $this->debug,
        );
    }

    private static function parseUrl(string $url): Address
    {
        $withScheme = str_contains($url, '://') ? $url : 'http://'.$url;
        $parts = parse_url($withScheme);

        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException(sprintf('%s is not a usable URL: "%s".', self::ENV_URL, $url));
        }

        return new Address($parts['host'], $parts['port'] ?? ToxiproxyClient::DEFAULT_PORT);
    }
}
