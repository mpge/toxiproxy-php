<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Client;

use Mpge\Toxiproxy\Client\ToxiproxyClient;
use Mpge\Toxiproxy\Exception\ApiException;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Exception\ProxyNotFoundException;
use Mpge\Toxiproxy\Exception\ToxicNotFoundException;
use Mpge\Toxiproxy\Proxy\ProxyDefinition;
use Mpge\Toxiproxy\Tests\Support\FakeTransport;
use Mpge\Toxiproxy\Toxic\Toxic;
use Mpge\Toxiproxy\Toxic\ToxicDirection;
use Mpge\Toxiproxy\Toxic\ToxicType;
use PHPUnit\Framework\TestCase;

final class ToxiproxyClientTest extends TestCase
{
    private FakeTransport $transport;

    private ToxiproxyClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new ToxiproxyClient('http://127.0.0.1:8474', $this->transport);
    }

    // -------------------------------------------------------------- server

    public function test_it_reads_the_server_version(): void
    {
        $this->transport->on('GET', '/version', 200, ['version' => '2.12.0']);

        self::assertSame('2.12.0', $this->client->version());
        self::assertSame(['GET /version'], $this->transport->trace());
    }

    public function test_is_running_is_false_when_nothing_answers(): void
    {
        $this->transport->refuseConnections();

        self::assertFalse($this->client->isRunning());
    }

    public function test_is_running_is_false_when_something_answers_that_is_not_toxiproxy(): void
    {
        $this->transport->on('GET', '/version', 502, 'Bad Gateway');

        self::assertFalse($this->client->isRunning());
    }

    public function test_reset_posts_to_the_reset_endpoint(): void
    {
        $this->transport->on('POST', '/reset', 204);

        $this->client->reset();

        self::assertSame(['POST /reset'], $this->transport->trace());
    }

    // -------------------------------------------------------------- proxies

    public function test_it_lists_proxies_keyed_by_name(): void
    {
        $this->transport->on('GET', '/proxies', 200, [
            'redis' => $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'),
            'mysql' => $this->proxyPayload('mysql', '127.0.0.1:13306', '127.0.0.1:3306'),
        ]);

        $proxies = $this->client->proxies();

        self::assertCount(2, $proxies);
        self::assertSame(['mysql', 'redis'], $proxies->names());
        self::assertSame('127.0.0.1:6379', $proxies->get('redis')->upstreamAddress());
    }

    public function test_it_creates_a_proxy_with_the_expected_body(): void
    {
        $this->transport->on('POST', '/proxies', 201, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'));

        $proxy = $this->client->createProxy('redis', '127.0.0.1:6379', '127.0.0.1:16379');

        self::assertSame([
            'name' => 'redis',
            'listen' => '127.0.0.1:16379',
            'upstream' => '127.0.0.1:6379',
            'enabled' => true,
        ], $this->transport->lastBody());

        self::assertSame(16379, $proxy->port());
        self::assertSame('127.0.0.1', $proxy->host());
    }

    /**
     * Toxiproxy binds the socket and reports the port it got, so asking for
     * port 0 is race-free where picking one in PHP is not.
     */
    public function test_an_automatic_port_is_delegated_to_the_server(): void
    {
        $this->transport->on('POST', '/proxies', 201, $this->proxyPayload('redis', '127.0.0.1:49812', '127.0.0.1:6379'));

        $proxy = $this->client->createProxy('redis', '127.0.0.1:6379');

        self::assertSame('127.0.0.1:0', $this->transport->lastBody()['listen']);
        self::assertSame(49812, $proxy->port());
    }

    /**
     * A disabled proxy never opens its listener, so the server would echo back
     * port 0 and leave the caller with nothing to connect to.
     */
    public function test_a_disabled_proxy_gets_a_real_port_allocated_locally(): void
    {
        $this->transport->on('POST', '/proxies', 201, $this->proxyPayload('redis', '127.0.0.1:40000', '127.0.0.1:6379', false));

        $this->client->createProxy('redis', '127.0.0.1:6379', enabled: false);

        /** @var string $listen */
        $listen = $this->transport->lastBody()['listen'];

        self::assertNotSame('127.0.0.1:0', $listen);
        self::assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $listen);
        self::assertGreaterThan(0, (int) explode(':', $listen)[1]);
    }

    public function test_listen_accepts_a_bare_port(): void
    {
        $this->transport->on('POST', '/proxies', 201, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'));

        $this->client->createProxy('redis', '127.0.0.1:6379', '16379');

        self::assertSame('127.0.0.1:16379', $this->transport->lastBody()['listen']);
    }

    public function test_a_missing_proxy_produces_a_typed_exception(): void
    {
        $this->transport->on('GET', '/proxies/ghost', 404, ['error' => 'proxy not found', 'status' => 404]);

        $this->expectException(ProxyNotFoundException::class);
        $this->expectExceptionMessage('Proxy "ghost" does not exist');

        $this->client->proxy('ghost');
    }

    public function test_find_proxy_returns_null_rather_than_throwing(): void
    {
        $this->transport->on('GET', '/proxies/ghost', 404, ['error' => 'proxy not found', 'status' => 404]);

        self::assertNull($this->client->findProxy('ghost'));
        self::assertFalse($this->client->hasProxy('ghost'));
    }

    public function test_a_non_404_error_is_not_swallowed_by_find(): void
    {
        $this->transport->on('GET', '/proxies/redis', 500, ['error' => 'boom', 'status' => 500]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('boom');

        $this->client->findProxy('redis');
    }

    public function test_it_updates_only_the_fields_you_name(): void
    {
        $this->transport->on('PATCH', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379', false));

        $this->client->updateProxy('redis', enabled: false);

        self::assertSame(['enabled' => false], $this->transport->lastBody());
        self::assertSame('PATCH', $this->transport->lastRequest()['method']);
    }

    /**
     * Toxiproxy accepts POST for updates but logs a deprecation for it, so this
     * package always uses PATCH.
     */
    public function test_updates_use_patch_not_the_deprecated_post(): void
    {
        $this->transport->on('PATCH', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'));

        $this->client->enableProxy('redis');

        self::assertSame(['PATCH /proxies/redis'], $this->transport->trace());
    }

    public function test_updating_nothing_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of listen, upstream or enabled');

        $this->client->updateProxy('redis');
    }

    public function test_it_deletes_a_proxy(): void
    {
        $this->transport->on('DELETE', '/proxies/redis', 204);

        $this->client->deleteProxy('redis');

        self::assertSame(['DELETE /proxies/redis'], $this->transport->trace());
    }

    public function test_deleting_a_missing_proxy_reports_which_one(): void
    {
        $this->transport->on('DELETE', '/proxies/ghost', 404, ['error' => 'proxy not found', 'status' => 404]);

        $this->expectException(ProxyNotFoundException::class);
        $this->expectExceptionMessage('"ghost"');

        $this->client->deleteProxy('ghost');
    }

    public function test_proxy_names_are_url_encoded(): void
    {
        $this->transport->on('GET', '/proxies/my%2Fproxy', 200, $this->proxyPayload('my/proxy', '127.0.0.1:1', '127.0.0.1:2'));

        $this->client->proxy('my/proxy');

        self::assertSame('/proxies/my%2Fproxy', $this->transport->lastRequest()['path']);
    }

    // ------------------------------------------------------------- populate

    public function test_populate_sends_a_list_and_reads_the_wrapped_response(): void
    {
        $this->transport->on('POST', '/populate', 201, [
            'proxies' => [
                $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'),
                $this->proxyPayload('mysql', '127.0.0.1:13306', '127.0.0.1:3306'),
            ],
        ]);

        $proxies = $this->client->populate([
            new ProxyDefinition('redis', '127.0.0.1:16379', '127.0.0.1:6379'),
            ['name' => 'mysql', 'listen' => '127.0.0.1:13306', 'upstream' => '127.0.0.1:3306'],
        ]);

        self::assertCount(2, $proxies);
        self::assertSame([
            ['name' => 'redis', 'listen' => '127.0.0.1:16379', 'upstream' => '127.0.0.1:6379', 'enabled' => true],
            ['name' => 'mysql', 'listen' => '127.0.0.1:13306', 'upstream' => '127.0.0.1:3306', 'enabled' => true],
        ], $this->transport->lastBody());
    }

    public function test_populating_nothing_makes_no_request(): void
    {
        self::assertCount(0, $this->client->populate([]));
        self::assertSame([], $this->transport->trace());
    }

    // --------------------------------------------------------------- toxics

    public function test_it_creates_a_toxic_with_the_full_payload(): void
    {
        $this->transport->on('POST', '/proxies/redis/toxics', 200, [
            'name' => 'latency_downstream',
            'type' => 'latency',
            'stream' => 'downstream',
            'toxicity' => 1.0,
            'attributes' => ['latency' => 1000, 'jitter' => 0],
        ]);

        $toxic = $this->client->createToxic('redis', Toxic::make(ToxicType::Latency, ['latency' => 1000]));

        self::assertSame([
            'name' => 'latency_downstream',
            'type' => 'latency',
            'stream' => 'downstream',
            'toxicity' => 1.0,
            'attributes' => ['latency' => 1000, 'jitter' => 0],
        ], $this->transport->lastBody());

        self::assertSame(1000, $toxic->attribute('latency'));
    }

    /**
     * Type and stream are immutable server-side, so sending them on an update
     * is at best noise and at worst confusing.
     */
    public function test_updating_a_toxic_sends_only_what_can_change(): void
    {
        $this->transport->on('PATCH', '/proxies/redis/toxics/latency_downstream', 200, [
            'name' => 'latency_downstream',
            'type' => 'latency',
            'stream' => 'downstream',
            'toxicity' => 0.5,
            'attributes' => ['latency' => 2000, 'jitter' => 0],
        ]);

        $this->client->updateToxic('redis', Toxic::make(ToxicType::Latency, ['latency' => 2000], toxicity: 0.5));

        self::assertSame([
            'toxicity' => 0.5,
            'attributes' => ['latency' => 2000, 'jitter' => 0],
        ], $this->transport->lastBody());
    }

    public function test_it_lists_toxics(): void
    {
        $this->transport->on('GET', '/proxies/redis/toxics', 200, [
            ['name' => 'latency_downstream', 'type' => 'latency', 'stream' => 'downstream', 'toxicity' => 1.0, 'attributes' => ['latency' => 10, 'jitter' => 0]],
            ['name' => 'bandwidth_upstream', 'type' => 'bandwidth', 'stream' => 'upstream', 'toxicity' => 1.0, 'attributes' => ['rate' => 50]],
        ]);

        $toxics = $this->client->toxics('redis');

        self::assertCount(2, $toxics);
        self::assertCount(1, $toxics->onStream(ToxicDirection::Upstream));
        self::assertCount(1, $toxics->ofType(ToxicType::Latency));
    }

    /**
     * Both the proxy and the toxic are addressed by the same URL, so a bare 404
     * is ambiguous. Disambiguating it is the difference between "you typo'd the
     * toxic name" and "the proxy was never created".
     */
    public function test_a_404_on_a_toxic_says_whether_the_proxy_or_the_toxic_is_missing(): void
    {
        $this->transport
            ->on('DELETE', '/proxies/redis/toxics/ghost', 404, ['error' => 'toxic not found', 'status' => 404])
            ->on('GET', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:1', '127.0.0.1:2'));

        $this->expectException(ToxicNotFoundException::class);
        $this->expectExceptionMessage('Toxic "ghost" does not exist on proxy "redis"');

        $this->client->deleteToxic('redis', 'ghost');
    }

    public function test_a_404_on_a_toxic_of_a_missing_proxy_blames_the_proxy(): void
    {
        $this->transport
            ->on('DELETE', '/proxies/ghost/toxics/latency_downstream', 404, ['error' => 'proxy not found', 'status' => 404])
            ->on('GET', '/proxies/ghost', 404, ['error' => 'proxy not found', 'status' => 404]);

        $this->expectException(ProxyNotFoundException::class);
        $this->expectExceptionMessage('Proxy "ghost"');

        $this->client->deleteToxic('ghost', 'latency_downstream');
    }

    public function test_toxic_names_are_url_encoded(): void
    {
        $this->transport->on('GET', '/proxies/redis/toxics/a%20b', 200, [
            'name' => 'a b', 'type' => 'noop', 'stream' => 'downstream', 'toxicity' => 1.0, 'attributes' => [],
        ]);

        self::assertNotNull($this->client->findToxic('redis', 'a b'));
        self::assertSame('/proxies/redis/toxics/a%20b', $this->transport->lastRequest()['path']);
    }

    // -------------------------------------------------------------- ensure

    public function test_ensure_proxy_creates_when_absent(): void
    {
        $this->transport
            ->on('GET', '/proxies/redis', 404, ['error' => 'proxy not found', 'status' => 404])
            ->on('POST', '/proxies', 201, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'));

        self::assertSame('redis', $this->client->ensureProxy('redis', '127.0.0.1:6379')->name());
        self::assertSame(1, $this->transport->countRequests('POST', '/proxies'));
    }

    public function test_ensure_proxy_reuses_a_matching_one_without_recreating_it(): void
    {
        $this->transport->on('GET', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'));

        $this->client->ensureProxy('redis', '127.0.0.1:6379');

        self::assertSame(0, $this->transport->countRequests('POST', '/proxies'));
        self::assertSame(0, $this->transport->countRequests('DELETE'));
    }

    /**
     * Reusing a proxy whose upstream changed would keep routing traffic to the
     * old address, which is worse than the cost of rebuilding it.
     */
    public function test_ensure_proxy_rebuilds_when_the_upstream_changed(): void
    {
        $this->transport
            ->on('GET', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379'))
            ->on('DELETE', '/proxies/redis', 204)
            ->on('POST', '/proxies', 201, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6380'));

        $this->client->ensureProxy('redis', '127.0.0.1:6380');

        self::assertSame(1, $this->transport->countRequests('DELETE', '/proxies/redis'));
        self::assertSame(1, $this->transport->countRequests('POST', '/proxies'));
    }

    public function test_ensure_proxy_only_toggles_enabled_when_that_is_the_sole_difference(): void
    {
        $this->transport
            ->on('GET', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379', true))
            ->on('PATCH', '/proxies/redis', 200, $this->proxyPayload('redis', '127.0.0.1:16379', '127.0.0.1:6379', false));

        $this->client->ensureProxy('redis', '127.0.0.1:6379', enabled: false);

        self::assertSame(['enabled' => false], $this->transport->lastBody());
        self::assertSame(0, $this->transport->countRequests('DELETE'));
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function proxyPayload(string $name, string $listen, string $upstream, bool $enabled = true): array
    {
        return [
            'name' => $name,
            'listen' => $listen,
            'upstream' => $upstream,
            'enabled' => $enabled,
            'toxics' => [],
        ];
    }
}
