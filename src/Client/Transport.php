<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use Mpge\Toxiproxy\Exception\ConnectionException;

/**
 * The seam between this package and however you want HTTP performed.
 *
 * The interface is deliberately tiny: Toxiproxy's API is a handful of JSON
 * endpoints on localhost, so a full PSR-18 stack is optional rather than
 * required. Implement this to plug in your own client, or use Psr18Transport
 * to hand off to any PSR-18 implementation you already have.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws ConnectionException when the server cannot be reached at all.
     *                             Non-2xx responses are returned, not thrown.
     */
    public function send(string $method, string $url, ?string $body = null, array $headers = []): Response;
}
