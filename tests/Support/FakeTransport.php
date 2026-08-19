<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Support;

use Mpge\Toxiproxy\Client\Response;
use Mpge\Toxiproxy\Client\Transport;
use Mpge\Toxiproxy\Exception\ConnectionException;
use PHPUnit\Framework\Assert;

/**
 * A Transport that answers from a routing table and records what it was asked.
 *
 * Lets the whole endpoint layer be tested for the exact bytes it puts on the
 * wire, which matters here: Toxiproxy's Go decoder silently drops fields it
 * does not recognise, so a wrong key produces a passing call and a toxic that
 * does nothing.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, path: string, body: ?string, headers: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, list<Response>> */
    private array $routes = [];

    /** @var array<string, Response> */
    private array $persistent = [];

    private ?string $connectionError = null;

    /**
     * Answer this route every time it is called.
     *
     * @param  array<mixed>|string|null  $body
     */
    public function on(string $method, string $path, int $status = 200, array|string|null $body = null): self
    {
        $this->persistent[$this->key($method, $path)] = new Response($status, $this->encode($body));

        return $this;
    }

    /**
     * Answer this route once. Queued responses are consumed before the
     * persistent one, so a sequence of differing answers can be scripted.
     *
     * @param  array<mixed>|string|null  $body
     */
    public function once(string $method, string $path, int $status = 200, array|string|null $body = null): self
    {
        $this->routes[$this->key($method, $path)][] = new Response($status, $this->encode($body));

        return $this;
    }

    /**
     * Make every request fail as if nothing were listening.
     */
    public function refuseConnections(string $reason = 'Connection refused'): self
    {
        $this->connectionError = $reason;

        return $this;
    }

    public function send(string $method, string $url, ?string $body = null, array $headers = []): Response
    {
        $path = $this->pathOf($url);

        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'body' => $body,
            'headers' => $headers,
        ];

        if ($this->connectionError !== null) {
            throw ConnectionException::forEndpoint($url, $this->connectionError);
        }

        $key = $this->key($method, $path);

        if (isset($this->routes[$key]) && $this->routes[$key] !== []) {
            return array_shift($this->routes[$key]);
        }

        return $this->persistent[$key]
            ?? new Response(404, '{"error": "proxy not found", "status": 404}');
    }

    // ------------------------------------------------------------- assertions

    /**
     * @return array{method: string, url: string, path: string, body: ?string, headers: array<string, string>}
     */
    public function lastRequest(): array
    {
        Assert::assertNotEmpty($this->requests, 'No requests were made.');

        return $this->requests[count($this->requests) - 1];
    }

    /**
     * The decoded JSON body of the last request.
     *
     * @return array<mixed>
     */
    public function lastBody(): array
    {
        $body = $this->lastRequest()['body'];

        Assert::assertIsString($body, 'The last request had no body.');

        /** @var array<mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return list<string>  "METHOD /path" for every request, in order
     */
    public function trace(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'].' '.$request['path'],
            $this->requests,
        );
    }

    public function countRequests(?string $method = null, ?string $path = null): int
    {
        return count(array_filter(
            $this->requests,
            static fn (array $request): bool => ($method === null || $request['method'] === $method)
                && ($path === null || $request['path'] === $path),
        ));
    }

    // ---------------------------------------------------------------- private

    private function key(string $method, string $path): string
    {
        return strtoupper($method).' '.'/'.ltrim($path, '/');
    }

    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * @param  array<mixed>|string|null  $body
     */
    private function encode(array|string|null $body): string
    {
        if ($body === null) {
            return '';
        }

        return is_string($body) ? $body : (string) json_encode($body, JSON_THROW_ON_ERROR);
    }
}
