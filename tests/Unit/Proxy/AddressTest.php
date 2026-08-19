<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Proxy;

use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use Mpge\Toxiproxy\Proxy\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function parseCases(): iterable
    {
        yield 'host and port' => ['127.0.0.1:6379', '127.0.0.1', 6379];
        yield 'hostname and port' => ['redis.internal:6379', 'redis.internal', 6379];
        yield 'port only with colon' => [':6379', '127.0.0.1', 6379];
        yield 'bare port' => ['6379', '127.0.0.1', 6379];
        yield 'ephemeral' => ['127.0.0.1:0', '127.0.0.1', 0];
        yield 'all interfaces' => ['0.0.0.0:8474', '0.0.0.0', 8474];
        yield 'ipv6 literal' => ['[::1]:6379', '::1', 6379];
        yield 'ipv6 full' => ['[2001:db8::1]:80', '2001:db8::1', 80];
        yield 'whitespace' => ['  127.0.0.1:6379  ', '127.0.0.1', 6379];
    }

    #[DataProvider('parseCases')]
    public function test_it_parses_the_address_forms_go_produces(string $input, string $host, int $port): void
    {
        $address = Address::parse($input);

        self::assertSame($host, $address->host);
        self::assertSame($port, $address->port);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'empty' => [''];
        yield 'host without port' => ['redis.internal'];
        yield 'port not numeric' => ['127.0.0.1:redis'];
        yield 'unterminated ipv6' => ['[::1:6379'];
        yield 'ipv6 without port' => ['[::1]'];
    }

    #[DataProvider('invalidCases')]
    public function test_it_rejects_things_that_are_not_addresses(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Address::parse($input);
    }

    public function test_a_port_outside_the_valid_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Address('127.0.0.1', 70000);
    }

    public function test_it_round_trips_back_to_a_string(): void
    {
        self::assertSame('127.0.0.1:6379', (string) Address::parse('127.0.0.1:6379'));
        self::assertSame('127.0.0.1:6379', Address::parse('6379')->toString());
    }

    public function test_ipv6_hosts_are_re_bracketed_on_output(): void
    {
        self::assertSame('[::1]:6379', Address::parse('[::1]:6379')->toString());
    }

    public function test_port_zero_is_recognised_as_a_request_for_any_free_port(): void
    {
        self::assertTrue(Address::parse('127.0.0.1:0')->isEphemeral());
        self::assertFalse(Address::parse('127.0.0.1:6379')->isEphemeral());
    }

    public function test_a_custom_default_host_is_honoured(): void
    {
        self::assertSame('0.0.0.0', Address::parse('6379', '0.0.0.0')->host);
        self::assertSame('0.0.0.0', Address::parse(':6379', '0.0.0.0')->host);
        // An explicit host in the input always wins over the default.
        self::assertSame('10.0.0.1', Address::parse('10.0.0.1:6379', '0.0.0.0')->host);
    }

    public function test_withers_produce_new_addresses(): void
    {
        $address = new Address('127.0.0.1', 0);

        self::assertSame(6379, $address->withPort(6379)->port);
        self::assertSame('0.0.0.0', $address->withHost('0.0.0.0')->host);
        self::assertSame(0, $address->port);
    }
}
