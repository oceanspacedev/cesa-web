<?php

namespace Cesa\Rekrutmen\Console\Commands;

use Cesa\Rekrutmen\Services\WhatsAppEngineClient;
use Cesa\Rekrutmen\Services\WhatsAppEngineProcess;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunWhatsAppEngineCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rekrutmen:whatsapp-engine {--install : Install Node dependencies only} {--ensure : Start engine in background if it is down}';

    /**
     * @var string
     */
    protected $description = 'Periksa service WhatsApp eksternal atau jalankan engine WhatsApp lokal';

    public function handle(WhatsAppEngineProcess $process, WhatsAppEngineClient $client): int
    {
        if (! $client->isLocalEngine()) {
            if ($this->option('install')) {
                $this->error('Engine WhatsApp eksternal dikelola oleh service terpisah; instalasi Node.js di CESA tidak diperlukan.');

                return self::FAILURE;
            }

            if ($client->isReady()) {
                $this->info('Service WhatsApp eksternal siap.');

                return self::SUCCESS;
            }

            $this->error($client->unavailableMessage());

            return self::FAILURE;
        }

        if ($this->option('install')) {
            if (! $process->installDependencies()) {
                $this->error('Gagal menginstal dependensi Node.js untuk engine WhatsApp.');

                return self::FAILURE;
            }

            $this->info('Dependensi engine WhatsApp siap.');

            return self::SUCCESS;
        }

        if ($this->option('ensure')) {
            if ($process->ensureRunning(true)) {
                $this->info('Engine WhatsApp rekrutmen siap di '.$client->baseUrl());

                return self::SUCCESS;
            }

            $this->error('Engine WhatsApp gagal dinyalakan. Cek storage/logs/rekrutmen-whatsapp-engine.log');

            return self::FAILURE;
        }

        if (! $process->installDependencies()) {
            $this->error('Node.js/npm tidak siap. Install Node.js lalu jalankan: php artisan rekrutmen:whatsapp-engine --install');

            return self::FAILURE;
        }

        $this->info('Menjalankan engine WhatsApp rekrutmen di '.$client->baseUrl());

        $foreground = new Process(
            [$process->nodeBinary(), $process->scriptPath()],
            $process->workingDirectory(),
            $process->environment(),
        );
        $foreground->setTimeout(null);
        $foreground->setIdleTimeout(null);

        $foreground->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $foreground->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
