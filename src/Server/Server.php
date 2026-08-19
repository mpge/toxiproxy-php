<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Client\ToxiproxyClient;

/**
 * A Toxiproxy server this package can start and stop.
 *
 * Two implementations: the native binary, which is the primary path, and a
 * Docker container, which is there for people who already run everything that
 * way.
 */
interface Server
{
    /**
     * Start the server, or adopt one already answering on the endpoint.
     *
     * @param  bool  $detached  leave it running after this PHP process exits
     */
    public function start(bool $detached = false): static;

    /**
     * Stop the server, but only if this object started it.
     *
     * @return bool  true when something was actually stopped
     */
    public function stop(float $graceSeconds = 5.0): bool;

    public function isRunning(): bool;

    /**
     * True when this object started the server it is talking to.
     */
    public function ownsProcess(): bool;

    /**
     * The base URL of the Toxiproxy API.
     */
    public function endpoint(): string;

    public function client(): ToxiproxyClient;

    /**
     * Whatever the server has logged so far.
     */
    public function logs(): string;
}
