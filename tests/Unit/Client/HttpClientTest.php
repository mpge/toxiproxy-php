<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Client;

use Mpge\Toxiproxy\Client\HttpClient;
use Mpge\Toxiproxy\Exception\ApiException;
use Mpge\Toxiproxy\Exception\ConnectionException;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'full url' => ['http://127.0.0.1:8474', 'http://127.0.0.1:8474'];
        yield 'trailing slash' => ['http://127.0.0.1:8474/', 'http://127.0.0.1:8474'];
        yield 'no scheme' => ['127.0.0.1:8474', 'http://127.0.0.1:8474'];
        yield 'whitespace' => ['  http://127.0.0.1:8474  ', 'http://127.0.0.1:8474'];
        yield 'https' => ['https://toxiproxy.internal', 'https://toxiproxy.internal'];
    }

    #[DataProvider('baseUrls')]
    public function test_it_normalises_the_base_url(string $input, string $expected): void
    {
        self::assertSame($expected, (new HttpClient($input, new FakeTransport()))->baseUrl());
    }

    public function test_an_empty_base_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpClient('   ', new FakeTransport());
    }

    /**
     * Toxiproxy answers 403 to any request whose User-Agent starts with
     * "Mozilla/", a guard against people poking the control plane from a
     * browser. Failing here beats a baffling 403 at runtime.
     */
    public function test_a_browser_user_agent_is_refused_before_it_can_cause_a_403(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('403');

        new HttpClient('http://127.0.0.1:8474', new FakeTransport(), 'Mozilla/5.0 (Macintosh)');
    }

    public function test_it_sends_a_user_agent_and_accepts_json(): void
    {
        $transport = (new FakeTransport())->on('GET', '/version', 200, ['version' => '2.12.0']);

        (new HttpClient('http://127.0.0.1:8474', $transport))->get('/version');

        $headers = $transport->lastRequest()['headers'];

        self::assertSame('application/json', $headers['Accept']);
        self::assertSame('toxiproxy-php', $headers['User-Agent']);
        self::assertArrayNotHasKey('Content-Type', $headers);
    }

    public function test_a_request_with_a_body_declares_its_content_type(): void
    {
        $transport = (new FakeTransport())->on('POST', '/proxies', 201, []);

        (new HttpClient('http://127.0.0.1:8474', $transport))->post('/proxies', ['name' => 'redis']);

        self::assertSame('application/json', $transport->lastRequest()['headers']['Content-Type']);
        self::assertSame('{"name":"redis"}', $transport->lastRequest()['body']);
    }

    public function test_slashes_in_the_body_are_not_escaped(): void
    {
        $transport = (new FakeTransport())->on('POST', '/proxies', 201, []);

        (new HttpClient('http://127.0.0.1:8474', $transport))->post('/proxies', ['upstream' => 'a/b']);

        self::assertSame('{"upstream":"a/b"}', $transport->lastRequest()['body']);
    }

    public function test_a_float_keeps_its_zero_fraction(): void
    {
        $transport = (new FakeTransport())->on('POST', '/x', 200, []);

        (new HttpClient('http://127.0.0.1:8474', $transport))->post('/x', ['toxicity' => 1.0]);

        self::assertSame('{"toxicity":1.0}', $transport->lastRequest()['body']);
    }

    public function test_it_surfaces_the_servers_own_error_message(): void
    {
        $transport = (new FakeTransport())->on('POST', '/proxies', 409, [
            'error' => 'proxy already exists',
            'status' => 409,
        ]);

        try {
            (new HttpClient('http://127.0.0.1:8474', $transport))->post('/proxies', ['name' => 'redis']);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(409, $e->statusCode);
            self::assertStringContainsString('proxy already exists', $e->getMessage());
            self::assertStringContainsString('POST /proxies', $e->getMessage());
        }
    }

    /**
     * A reverse proxy in front of Toxiproxy may answer with plain text, so the
     * body has to be usable even when it is not the expected envelope.
     */
    public function test_a_non_json_error_body_is_still_reported(): void
    {
        $transport = (new FakeTransport())->on('GET', '/version', 502, '502 Bad Gateway');

        try {
            (new HttpClient('http://127.0.0.1:8474', $transport))->get('/version');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('502 Bad Gateway', $e->getMessage());
        }
    }

    public function test_an_empty_error_body_still_names_the_status(): void
    {
        $transport = (new FakeTransport())->on('GET', '/version', 500, '');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new HttpClient('http://127.0.0.1:8474', $transport))->get('/version');
    }

    public function test_a_204_with_no_body_decodes_to_an_empty_array(): void
    {
        $transport = (new FakeTransport())->on('POST', '/reset', 204, '');

        self::assertSame([], (new HttpClient('http://127.0.0.1:8474', $transport))->post('/reset'));
    }

    public function test_a_success_body_that_is_not_json_is_an_error(): void
    {
        $transport = (new FakeTransport())->on('GET', '/version', 200, 'not json');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not valid JSON');

        (new HttpClient('http://127.0.0.1:8474', $transport))->get('/version');
    }

    public function test_a_scalar_json_body_where_an_object_is_expected_is_an_error(): void
    {
        $transport = (new FakeTransport())->on('GET', '/version', 200, '"just a string"');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('where a JSON object or array was expected');

        (new HttpClient('http://127.0.0.1:8474', $transport))->get('/version');
    }

    public function test_an_unreachable_server_raises_a_connection_exception_not_an_api_one(): void
    {
        $transport = (new FakeTransport())->refuseConnections();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('doctor');

        (new HttpClient('http://127.0.0.1:8474', $transport))->get('/version');
    }

    public function test_paths_are_joined_without_doubling_the_slash(): void
    {
        $transport = (new FakeTransport())->on('GET', '/version', 200, ['version' => '1']);
        $client = new HttpClient('http://127.0.0.1:8474', $transport);

        $client->get('/version');
        $client->get('version');

        self::assertSame(
            ['http://127.0.0.1:8474/version', 'http://127.0.0.1:8474/version'],
            array_map(static fn (array $r): string => $r['url'], $transport->requests),
        );
    }
}
