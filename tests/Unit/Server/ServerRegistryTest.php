<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Tests\Unit\Server;

use Mpge\Toxiproxy\Server\ProcessControl;
use Mpge\Toxiproxy\Server\ServerRecord;
use Mpge\Toxiproxy\Server\ServerRegistry;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class ServerRegistryTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/toxiproxy-registry-'.bin2hex(random_bytes(6));
    }

    #[After]
    protected function removeHome(): void
    {
        foreach (glob($this->home.'/run/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->home.'/run');
        @rmdir($this->home);
    }

    public function test_it_records_and_finds_a_server(): void
    {
        $registry = ServerRegistry::inHome($this->home);
        $record = $this->record();

        $registry->record($record);
        $found = $registry->find('127.0.0.1', 8474);

        self::assertNotNull($found);
        self::assertSame(4242, $found->pid);
        self::assertSame('/opt/toxiproxy-server', $found->binary);
        self::assertTrue($found->detached);
    }

    /**
     * The registry is what makes "never kill somebody else's server" work
     * across process boundaries: no record means not ours.
     */
    public function test_an_endpoint_with_no_record_is_not_ours(): void
    {
        self::assertNull(ServerRegistry::inHome($this->home)->find('127.0.0.1', 8474));
    }

    public function test_records_are_kept_per_endpoint(): void
    {
        $registry = ServerRegistry::inHome($this->home);

        $registry->record($this->record(port: 8474, pid: 1));
        $registry->record($this->record(port: 9474, pid: 2));

        self::assertSame(1, $registry->find('127.0.0.1', 8474)?->pid);
        self::assertSame(2, $registry->find('127.0.0.1', 9474)?->pid);
        self::assertCount(2, $registry->all());
    }

    public function test_forgetting_removes_only_that_endpoint(): void
    {
        $registry = ServerRegistry::inHome($this->home);

        $registry->record($this->record(port: 8474));
        $registry->record($this->record(port: 9474));
        $registry->forget('127.0.0.1', 8474);

        self::assertNull($registry->find('127.0.0.1', 8474));
        self::assertNotNull($registry->find('127.0.0.1', 9474));
    }

    public function test_forgetting_something_that_is_not_there_is_harmless(): void
    {
        ServerRegistry::inHome($this->home)->forget('127.0.0.1', 8474);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A hostname can contain characters that are not safe in a filename, and a
     * crafted one must not escape the registry directory.
     */
    public function test_hostnames_are_sanitised_into_filenames(): void
    {
        $registry = ServerRegistry::inHome($this->home);

        $registry->record($this->record(host: '../../etc/passwd'));

        $files = glob($this->home.'/run/*.json') ?: [];

        self::assertCount(1, $files);
        self::assertStringNotContainsString('..', basename($files[0]));
        self::assertNotNull($registry->find('../../etc/passwd', 8474));
    }

    public function test_a_corrupt_record_is_ignored_rather_than_fatal(): void
    {
        $registry = ServerRegistry::inHome($this->home);
        $registry->record($this->record());

        $files = glob($this->home.'/run/*.json') ?: [];
        file_put_contents($files[0], 'this is not json');

        self::assertNull($registry->find('127.0.0.1', 8474));
        self::assertSame([], $registry->all());
    }

    public function test_a_record_missing_required_fields_is_ignored(): void
    {
        $registry = ServerRegistry::inHome($this->home);
        $registry->record($this->record());

        $files = glob($this->home.'/run/*.json') ?: [];
        file_put_contents($files[0], (string) json_encode(['host' => '127.0.0.1']));

        self::assertNull($registry->find('127.0.0.1', 8474));
    }

    /**
     * A server that crashed leaves a record behind. Without pruning it would
     * look alive forever and `stop` would keep trying to kill a dead PID.
     */
    public function test_pruning_drops_records_whose_process_is_gone(): void
    {
        $registry = ServerRegistry::inHome($this->home);
        $registry->record($this->record(port: 8474, pid: 1));
        $registry->record($this->record(port: 9474, pid: 2));

        $removed = $registry->prune(new class extends ProcessControl
        {
            public function isAlive(int $pid): bool
            {
                return $pid === 2;
            }
        });

        self::assertCount(1, $removed);
        self::assertSame(1, $removed[0]->pid);
        self::assertNull($registry->find('127.0.0.1', 8474));
        self::assertNotNull($registry->find('127.0.0.1', 9474));
    }

    public function test_a_record_reports_its_uptime(): void
    {
        $record = new ServerRecord('127.0.0.1', 8474, 1, '/bin/x', 1_000_000, true, 5);

        self::assertSame(30, $record->uptimeSeconds(1_000_030));
        self::assertSame(0, $record->uptimeSeconds(999_000));
        self::assertSame('127.0.0.1:8474', $record->endpoint());
    }

    public function test_a_record_survives_a_json_round_trip(): void
    {
        $record = $this->record();
        $decoded = ServerRecord::decode((string) json_encode($record));

        self::assertNotNull($decoded);
        self::assertEquals($record, $decoded);
    }

    private function record(string $host = '127.0.0.1', int $port = 8474, int $pid = 4242): ServerRecord
    {
        return new ServerRecord($host, $port, $pid, '/opt/toxiproxy-server', 1_700_000_000, true, 99);
    }
}
