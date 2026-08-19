<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Client\Transports;
use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ToxiproxyException;

/**
 * Cheap questions about an endpoint, asked without committing to anything.
 *
 * Deliberately separate from ToxiproxyClient: probing runs in tight loops
 * during startup and must never throw, whereas the client throws by design.
 */
final class ToxiproxyProbe
{
    public function __construct(
        private readonly Configuration $config,
        private readonly float $timeout = 1.0,
    ) {
    }

    /**
     * Is something listening on the API port at all?
     */
    public function isPortOpen(): bool
    {
        $connection = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->config->host, $this->config->port),
            $errno,
            $errstr,
            $this->timeout,
        );

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    /**
     * Is the thing listening actually Toxiproxy, rather than some other service
     * that happened to claim the port?
     */
    public function isToxiproxy(): bool
    {
        return $this->version() !== null;
    }

    public function version(): ?string
    {
        try {
            return $this->client()->version();
        } catch (ToxiproxyException) {
            return null;
        }
    }

    private function client(): ToxiproxyClient
    {
        return new ToxiproxyClient($this->config->apiUrl(), Transports::default($this->timeout));
    }
}
