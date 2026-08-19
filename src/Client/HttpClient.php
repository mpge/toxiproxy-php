<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

use JsonException;
use Mpge\Toxiproxy\Exception\ApiException;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;

/**
 * JSON plumbing over a Transport: URL building, encoding, decoding and turning
 * Toxiproxy's error envelope into exceptions.
 *
 * Kept separate from ToxiproxyClient so the endpoint-level API can be unit
 * tested against a fake transport with no server and no network.
 */
final class HttpClient
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly Transport $transport,
        private readonly string $userAgent = 'toxiproxy-php',
    ) {
        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            throw new InvalidArgumentException('The Toxiproxy base URL cannot be empty.');
        }

        if (! str_contains($normalized, '://')) {
            $normalized = 'http://'.$normalized;
        }

        // Toxiproxy blocks any request whose User-Agent begins with "Mozilla/",
        // a guard against people poking the control plane from a browser.
        if (str_starts_with($userAgent, 'Mozilla/')) {
            throw new InvalidArgumentException(
                'Toxiproxy rejects User-Agent values starting with "Mozilla/" (HTTP 403). Choose another.',
            );
        }

        $this->baseUrl = $normalized;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<mixed>
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param  array<mixed>|object|null  $body
     * @return array<mixed>
     */
    public function post(string $path, array|object|null $body = null): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * Toxiproxy accepts both POST and PATCH for updates but logs a deprecation
     * warning for POST, so this package always uses PATCH.
     *
     * @param  array<mixed>|object|null  $body
     * @return array<mixed>
     */
    public function patch(string $path, array|object|null $body = null): array
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): void
    {
        $this->request('DELETE', $path);
    }

    /**
     * Perform a request without decoding, for endpoints whose success response
     * is empty (204) or whose failure the caller wants to inspect.
     */
    public function send(string $method, string $path, string|null $rawBody = null): Response
    {
        return $this->transport->send(
            $method,
            $this->baseUrl.'/'.ltrim($path, '/'),
            $rawBody,
            $this->headers($rawBody !== null),
        );
    }

    /**
     * @param  array<mixed>|object|null  $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, array|object|null $body = null): array
    {
        $encoded = $body === null ? null : $this->encode($body);
        $response = $this->send($method, $path, $encoded);

        if (! $response->isSuccessful()) {
            throw ApiException::fromResponse($method, $path, $response->status, $response->body);
        }

        if ($response->isEmpty()) {
            return [];
        }

        return $this->decode($response->body, $method, $path);
    }

    /**
     * @param  array<mixed>|object  $body
     */
    private function encode(array|object $body): string
    {
        try {
            // PRESERVE_ZERO_FRACTION keeps a toxicity of 1.0 from being written
            // as the integer 1. Go decodes either into float32, but sending the
            // right JSON type costs nothing and reads correctly in a request log.
            return json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Could not encode the request body as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body, string $method, string $path): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException(
                sprintf('%s %s returned a body that is not valid JSON: %s', $method, $path, $e->getMessage()),
                0,
                $method,
                $path,
                $body,
            );
        }

        if (! is_array($decoded)) {
            throw new ApiException(
                sprintf('%s %s returned %s where a JSON object or array was expected.', $method, $path, get_debug_type($decoded)),
                0,
                $method,
                $path,
                $body,
            );
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $hasBody): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }
}
