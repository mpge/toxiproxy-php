<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Integration;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ToxiproxyException;
use Mpge\Toxiproxy\Testing\InteractsWithToxiproxy;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that drive a real Toxiproxy server.
 *
 * The server is started once for the whole process by the same trait users get,
 * so these tests exercise the shipped testing integration rather than a private
 * harness. On first run the binary is downloaded, which takes a few seconds;
 * afterwards it is cached.
 */
abstract class IntegrationTestCase extends TestCase
{
    use InteractsWithToxiproxy;

    /** @var list<resource> */
    private array $sockets = [];

    protected function setUp(): void
    {
        try {
            $this->toxiproxy()->version();
        } catch (ToxiproxyException $e) {
            self::markTestSkipped('No Toxiproxy server available: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }

        $this->sockets = [];

        $this->toxiproxy()->flush();
    }

    protected function toxiproxyConfiguration(): Configuration
    {
        // Kept off 8474 so a Toxiproxy the developer is already using is
        // neither disturbed nor mistaken for one of ours.
        return Configuration::fromEnvironment()->withLogLevel('warn');
    }

    /**
     * A TCP server that echoes back whatever it is sent.
     *
     * Something real has to sit behind the proxy or the toxics have no traffic
     * to act on, and an echo server is the smallest thing that qualifies.
     *
     * @return array{0: resource, 1: string}  the listening socket and its address
     */
    protected function echoServer(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            self::fail(sprintf('Could not start an echo server: %s (%d)', $errstr, $errno));
        }

        $this->sockets[] = $socket;
        $address = stream_socket_get_name($socket, false);

        self::assertIsString($address);

        return [$socket, $address];
    }

    /**
     * Connect through a proxy, accept the connection on the echo server, and
     * hand back both ends.
     *
     * @param  resource  $server
     * @return array{0: resource, 1: resource}
     */
    protected function connectThrough(string $listen, $server, float $timeout = 5.0): array
    {
        $client = @stream_socket_client('tcp://'.$listen, $errno, $errstr, $timeout);

        if ($client === false) {
            self::fail(sprintf('Could not connect to the proxy at %s: %s (%d)', $listen, $errstr, $errno));
        }

        $this->sockets[] = $client;

        $accepted = @stream_socket_accept($server, $timeout);

        if ($accepted === false) {
            self::fail('The echo server never saw the connection.');
        }

        $this->sockets[] = $accepted;

        return [$client, $accepted];
    }

    /**
     * How long a full round trip through the proxy takes, in milliseconds.
     *
     * @param  resource  $server
     */
    protected function roundTripMilliseconds(string $listen, $server, string $payload = "ping\n"): float
    {
        $started = microtime(true);

        [$client, $accepted] = $this->connectThrough($listen, $server);

        fwrite($client, $payload);
        $received = fgets($accepted);
        fwrite($accepted, $received === false ? $payload : $received);
        fgets($client);

        $elapsed = (microtime(true) - $started) * 1000;

        fclose($client);
        fclose($accepted);

        return $elapsed;
    }
}
