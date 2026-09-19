<?php

namespace Cesa\Rekrutmen\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class WhatsAppEngineProcess
{
    public function __construct(
        protected WhatsAppEngineClient $client,
    ) {}

    public function ensureRunning(bool $force = false): bool
    {
        if ($this->client->isReady()) {
            return true;
        }

        if (! $this->client->isLocalEngine() || app()->runningUnitTests()) {
            return false;
        }

        if (! $force && ! (bool) config('rekrutmen.notifications.whatsapp.auto_start', true)) {
            return false;
        }

        if ($this->isPidAlive()) {
            return $this->waitUntilReady(8);
        }

        if (! is_file($this->workingDirectory().'/node_modules/@whiskeysockets/baileys/package.json')) {
            return false;
        }

        $lock = $this->acquireStartLock();
        if ($lock === false) {
            return $this->waitUntilReady(10);
        }

        try {
            if ($this->client->isReady() || $this->isPidAlive()) {
                return $this->waitUntilReady(8);
            }

            $this->start();
        } finally {
            $this->releaseStartLock($lock);
        }

        return $this->waitUntilReady(10);
    }

    public function start(): void
    {
        if (! $this->client->isLocalEngine()) {
            throw new RuntimeException('Engine WhatsApp eksternal dikelola oleh service terpisah.');
        }

        File::ensureDirectoryExists($this->sessionRoot());
        File::ensureDirectoryExists(dirname($this->pidPath()));
        File::ensureDirectoryExists(dirname($this->logPath()));

        $port = (string) (parse_url($this->client->baseUrl(), PHP_URL_PORT) ?: '3318');

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf(
                'start "" /B cmd /C "set REKRUTMEN_WA_ENGINE_PORT=%s& set REKRUTMEN_WA_ENGINE_HOST=127.0.0.1& set REKRUTMEN_WA_SESSION_ROOT=%s& set REKRUTMEN_WA_LOG_LEVEL=error& "%s" "%s" >> "%s" 2>&1"',
                $port,
                $this->sessionRoot(),
                $this->nodeBinary(),
                $this->scriptPath(),
                $this->logPath()
            );

            pclose(popen($cmd, 'r'));

            return;
        }

        $command = sprintf(
            'REKRUTMEN_WA_ENGINE_PORT=%s REKRUTMEN_WA_ENGINE_HOST=%s REKRUTMEN_WA_SESSION_ROOT=%s REKRUTMEN_WA_LOG_LEVEL=error nohup %s %s >> %s 2>&1 & echo $!',
            escapeshellarg($port),
            escapeshellarg('127.0.0.1'),
            escapeshellarg($this->sessionRoot()),
            escapeshellarg($this->nodeBinary()),
            escapeshellarg($this->scriptPath()),
            escapeshellarg($this->logPath()),
        );

        $process = Process::fromShellCommandline($command, $this->workingDirectory());
        $process->setTimeout(15);
        $process->run();

        $pid = trim($process->getOutput());
        if ($pid !== '' && ctype_digit($pid)) {
            File::put($this->pidPath(), $pid);
        }
    }

    public function isPidAlive(): bool
    {
        if (! File::exists($this->pidPath())) {
            return false;
        }

        $pid = (int) trim((string) File::get($this->pidPath()));
        if ($pid <= 1) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec('tasklist /FI "PID eq '.((int) $pid).'"', $output);

            return count($output) > 1 && ! str_contains(implode(' ', $output), 'No tasks');
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        exec('ps -p '.((string) $pid).' -o pid=', $output);

        return $output !== [];
    }

    public function installDependencies(): bool
    {
        if (! $this->client->isLocalEngine()) {
            return false;
        }

        $nodeModules = $this->workingDirectory().DIRECTORY_SEPARATOR.'node_modules';

        if (is_file($nodeModules.'/@whiskeysockets/baileys/package.json') && is_file($nodeModules.'/pino/package.json') && is_file($nodeModules.'/qrcode/package.json')) {
            return true;
        }

        if (! is_file($this->scriptPath())) {
            return false;
        }

        try {
            $process = new Process(
                [$this->npmBinary(), 'ci', '--omit=dev'],
                $this->workingDirectory(),
            );
            $process->setTimeout(180);
            $process->run();

            return $process->isSuccessful() && is_dir($nodeModules);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $port = (string) (parse_url($this->client->baseUrl(), PHP_URL_PORT) ?: '3318');
        $inherited = [];

        foreach (getenv() ?: [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $inherited[$key] = $value;
            }
        }

        return array_merge($inherited, [
            'REKRUTMEN_WA_ENGINE_PORT'  => $port,
            'REKRUTMEN_WA_ENGINE_HOST'  => '127.0.0.1',
            'REKRUTMEN_WA_SESSION_ROOT' => $this->sessionRoot(),
            'REKRUTMEN_WA_LOG_LEVEL'    => 'error',
        ]);
    }

    public function workingDirectory(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'whatsapp-engine';
    }

    public function scriptPath(): string
    {
        return $this->workingDirectory().DIRECTORY_SEPARATOR.'server.mjs';
    }

    public function sessionRoot(): string
    {
        return storage_path('app/rekrutmen/whatsapp-sessions');
    }

    public function nodeBinary(): string
    {
        return (string) config('rekrutmen.notifications.whatsapp.node_binary', 'node');
    }

    public function npmBinary(): string
    {
        $node = $this->nodeBinary();

        if (str_ends_with($node, 'node')) {
            return substr($node, 0, -4).'npm';
        }

        return 'npm';
    }

    protected function pidPath(): string
    {
        return storage_path('app/rekrutmen/whatsapp-engine.pid');
    }

    protected function lockPath(): string
    {
        return storage_path('app/rekrutmen/whatsapp-engine.lock');
    }

    protected function logPath(): string
    {
        return storage_path('logs/rekrutmen-whatsapp-engine.log');
    }

    protected function waitUntilReady(int $attempts): bool
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($this->client->isReady()) {
                return true;
            }

            usleep(400000);
        }

        return $this->client->isReady();
    }

    /**
     * @return resource|false
     */
    protected function acquireStartLock()
    {
        File::ensureDirectoryExists(dirname($this->lockPath()));

        $handle = fopen($this->lockPath(), 'c+');
        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    protected function releaseStartLock(mixed $handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
