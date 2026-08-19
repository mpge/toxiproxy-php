<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use Mpge\Toxiproxy\Exception\ConnectionException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Bridges any PSR-18 client into this package.
 *
 * Nothing here requires PSR-18 to be installed. Use this only if your project
 * already has an HTTP client you would rather route Toxiproxy calls through,
 * for example to reuse connection pooling or request logging.
 *
 *     $transport = new Psr18Transport($client, $requestFactory, $streamFactory);
 *     $toxiproxy = Toxiproxy::connect(transport: $transport);
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public static function isSupported(): bool
    {
        return interface_exists(ClientInterface::class);
    }

    public function send(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw ConnectionException::forEndpoint($url, $e->getMessage(), $e);
        }

        return new Response($response->getStatusCode(), (string) $response->getBody());
    }
}
