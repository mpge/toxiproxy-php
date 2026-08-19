<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Server;

use Mpge\Toxiproxy\Configuration;
use Mpge\Toxiproxy\Exception\ServerException;
use Symfony\Component\Process\Process;

/**
 * Starting and stopping the Go server process.
 *
 * Two modes, because the package has two very different callers:
 *
 *   Attached  - a Symfony Process tied to this PHP process. When PHP exits, so
 *               does the server. That is exactly what a test suite wants: no
 *               orphan left behind if the run is interrupted.
 *
 *   Detached  - survives this PHP process, so `vendor/bin/toxiproxy-php start`
 *               can hand you a server and return to the shell. The PID goes in
 *               the registry so a later `stop` can find it.
 *
 * Either way the process is only ever spawned after checking that nothing is
 * already answering on the API port, and the PID is recorded so nothing this
 * package did not start can be killed by it.
 */
final class ProcessManager
{
    public function __construct(
        private readonly Configuration $config,
        private readonly ServerRegistry $registry,
        private readonly ProcessControl $processes = new ProcessControl(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function command(string $binary): array
    {
        return [
            $binary,
            '-host', $this->config->host,
            '-port', (string) $this->config->port,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        // Toxiproxy reads its log level from the environment, not a flag.
        return ['LOG_LEVEL' => $this->config->logLevel];
    }

    /**
     * Start a server tied to the lifetime of this PHP process.
     */
    public function startAttached(string $binary): Process
    {
        $this->assertPortIsFree();

        $process = new Process(
            $this->command($binary),
            null,
            $this->environment() + ['PATH' => getenv('PATH') === false ? '' : getenv('PATH')],
        );

        // The server is meant to run until told otherwise; Symfony's default
        // 60 second timeout would kill it mid-suite.
        $process->setTimeout(null);
        $process->start();

        $this->awaitReadiness(
            fn (): bool => $process->isRunning(),
            fn (): string => $process->getOutput().$process->getErrorOutput(),
            fn (): ?int => $process->isRunning() ? null : $process->getExitCode(),
        );

        $pid = $process->getPid();

        if ($pid !== null) {
            $this->registry->record(new ServerRecord(
                $this->config->host,
                $this->config->port,
                $pid,
                $binary,
                time(),
                detached: false,
                startedByPid: getmypid() === false ? 0 : getmypid(),
            ));
        }

        return $process;
    }

    /**
     * Start a server that outlives this PHP process.
     *
     * @return ServerRecord  the recorded server, including its PID
     */
    public function startDetached(string $binary, ?string $logFile = null): ServerRecord
    {
        $this->assertPortIsFree();

        $log = $logFile ?? $this->defaultLogFile();
        $this->ensureLogDirectory($log);

        $pid = PHP_OS_FAMILY === 'Windows'
            ? $this->spawnWindows($binary, $log)
            : $this->spawnUnix($binary, $log);

        $record = new ServerRecord(
            $this->config->host,
            $this->config->port,
            $pid,
            $binary,
            time(),
            detached: true,
            startedByPid: getmypid() === false ? 0 : getmypid(),
        );

        $this->registry->record($record);

        try {
            $this->awaitReadiness(
                fn (): bool => $this->processes->isAlive($pid),
                fn (): string => is_file($log) ? (string) @file_get_contents($log) : '',
                fn (): ?int => $this->processes->isAlive($pid) ? null : 1,
            );
        } catch (ServerException $e) {
            $this->processes->terminate($pid);
            $this->registry->forget($this->config->host, $this->config->port);

            throw $e;
        }

        return $record;
    }

    /**
     * Stop a server we started, identified by its registry record.
     */
    public function stopRecorded(ServerRecord $record, float $graceSeconds = 5.0): bool
    {
        $stopped = $this->processes->terminate($record->pid, $graceSeconds);
        $this->registry->forget($record->host, $record->port);

        return $stopped;
    }

    public function registry(): ServerRegistry
    {
        return $this->registry;
    }

    public function defaultLogFile(): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->config->homeDirectory(), '/\\'),
            'logs',
            sprintf('toxiproxy-%s-%d.log', preg_replace('/[^A-Za-z0-9._-]/', '_', $this->config->host) ?? 'host', $this->config->port),
        ]);
    }

    /**
     * Refuse to spawn on a port that is already taken, and say what is on it.
     */
    private function assertPortIsFree(): void
    {
        if (! $this->isPortOccupied()) {
            return;
        }

        $occupant = (new ToxiproxyProbe($this->config))->isToxiproxy()
            ? 'a Toxiproxy server this package did not start'
            : 'another process';

        throw ServerException::portInUse($this->config->host, $this->config->port, $occupant);
    }

    private function isPortOccupied(): bool
    {
        $connection = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->config->host, $this->config->port),
            $errno,
            $errstr,
            0.5,
        );

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    /**
     * Poll the API until it answers, the process dies, or we run out of patience.
     *
     * @param  callable(): bool     $isRunning
     * @param  callable(): string   $readLog
     * @param  callable(): (int|null)  $exitCode
     */
    private function awaitReadiness(callable $isRunning, callable $readLog, callable $exitCode): void
    {
        $probe = new ToxiproxyProbe($this->config);
        $deadline = microtime(true) + $this->config->startTimeout;

        while (microtime(true) < $deadline) {
            if (! $isRunning()) {
                throw ServerException::exitedEarly($exitCode() ?? 1, $readLog());
            }

            if ($probe->isToxiproxy()) {
                return;
            }

            usleep(50_000);
        }

        throw ServerException::didNotBecomeReady(
            $this->config->apiUrl(),
            $this->config->startTimeout,
            $readLog(),
        );
    }

    /**
     * nohup detaches from the terminal, setsid from the process group, and
     * `echo $!` hands back the PID of the server rather than of the shell.
     */
    private function spawnUnix(string $binary, string $log): int
    {
        $command = sprintf(
            '%s %s >> %s 2>&1 & echo $!',
            $this->environmentPrefix(),
            implode(' ', array_map('escapeshellarg', $this->command($binary))),
            escapeshellarg($log),
        );

        $process = new Process(['/bin/sh', '-c', $command]);
        $process->setTimeout(15.0);
        $process->run();

        $pid = (int) trim($process->getOutput());

        if ($pid <= 0) {
            throw ServerException::exitedEarly(
                $process->getExitCode() ?? 1,
                $process->getErrorOutput() ?: 'the shell returned no PID',
            );
        }

        return $pid;
    }

    /**
     * PowerShell is the only thing guaranteed present on Windows that both
     * detaches a process and reports its PID.
     */
    private function spawnWindows(string $binary, string $log): int
    {
        $arguments = array_slice($this->command($binary), 1);

        $script = sprintf(
            '$env:LOG_LEVEL=%s; '
            .'$p = Start-Process -FilePath %s -ArgumentList %s -RedirectStandardOutput %s '
            .'-RedirectStandardError %s -WindowStyle Hidden -PassThru; $p.Id',
            $this->powerShellString($this->config->logLevel),
            $this->powerShellString($binary),
            implode(',', array_map($this->powerShellString(...), $arguments)),
            $this->powerShellString($log),
            $this->powerShellString($log.'.err'),
        );

        $process = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);
        $process->setTimeout(30.0);
        $process->run();

        $pid = (int) trim($process->getOutput());

        if ($pid <= 0) {
            throw ServerException::exitedEarly(
                $process->getExitCode() ?? 1,
                $process->getErrorOutput() ?: 'PowerShell returned no process id',
            );
        }

        return $pid;
    }

    private function environmentPrefix(): string
    {
        $parts = [];

        foreach ($this->environment() as $name => $value) {
            $parts[] = $name.'='.escapeshellarg($value);
        }

        // nohup detaches from the terminal so the server survives the shell
        // exiting; env carries the log level, which Toxiproxy has no flag for.
        return $parts === [] ? 'nohup' : 'env '.implode(' ', $parts).' nohup';
    }

    private function powerShellString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function ensureLogDirectory(string $log): void
    {
        $directory = dirname($log);

        if (! is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }
    }
}
