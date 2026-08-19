<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Symfony\Component\Process\Process;

/**
 * Signalling and inspecting a process by PID, across platforms.
 *
 * Symfony's Process class covers processes we hold an object for; this covers
 * the ones we only know by number, which is every detached server started by a
 * previous run of the CLI.
 */
class ProcessControl
{
    /**
     * errno EPERM. posix_kill() fails with it when the process exists but
     * belongs to another user, which still counts as alive.
     */
    private const EPERM = 1;

    public function __construct(private readonly bool $isWindows = PHP_OS_FAMILY === 'Windows')
    {
    }

    public function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (! $this->isWindows && function_exists('posix_kill')) {
            // Signal 0 performs the permission and existence checks without
            // actually delivering anything.
            return @posix_kill($pid, 0) || posix_get_last_error() === self::EPERM;
        }

        return $this->isWindows
            ? $this->windowsProcessExists($pid)
            : $this->run(['kill', '-0', (string) $pid]);
    }

    /**
     * Ask the process to shut down, then insist if it will not.
     *
     * Toxiproxy handles SIGINT and SIGTERM by draining its HTTP server, so the
     * polite signal is worth sending first.
     */
    public function terminate(int $pid, float $graceSeconds = 5.0): bool
    {
        if (! $this->isAlive($pid)) {
            return false;
        }

        $this->signal($pid, graceful: true);

        $deadline = microtime(true) + $graceSeconds;

        while (microtime(true) < $deadline) {
            if (! $this->isAlive($pid)) {
                return true;
            }

            usleep(50_000);
        }

        $this->signal($pid, graceful: false);

        return ! $this->isAlive($pid);
    }

    private function signal(int $pid, bool $graceful): void
    {
        if ($this->isWindows) {
            // Windows has no SIGTERM for another process; taskkill without /F
            // asks politely, with /F does not.
            $this->run($graceful
                ? ['taskkill', '/PID', (string) $pid, '/T']
                : ['taskkill', '/PID', (string) $pid, '/T', '/F']);

            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, $graceful ? 15 : 9);

            return;
        }

        $this->run(['kill', $graceful ? '-TERM' : '-KILL', (string) $pid]);
    }

    private function windowsProcessExists(int $pid): bool
    {
        $process = new Process(['tasklist', '/FI', sprintf('PID eq %d', $pid), '/NH', '/FO', 'CSV']);
        $process->setTimeout(10.0);
        $process->run();

        // tasklist exits 0 with a "no tasks" banner when nothing matches, so
        // the PID has to actually appear in the output.
        return str_contains($process->getOutput(), '"'.$pid.'"');
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): bool
    {
        $process = new Process($command);
        $process->setTimeout(10.0);
        $process->run();

        return $process->isSuccessful();
    }
}
