<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WagHubClient;
use Illuminate\Console\Command;

class WagHubStatus extends Command
{
    protected $signature = 'wag:status';

    protected $description = 'Periksa konfigurasi dan koneksi layanan WhatsApp WAG Hub';

    public function handle(WagHubClient $hub): int
    {
        if (! $hub->isEngineConfigured()) {
            $this->error('Isi WAG_URL dan WAG_TOKEN dari menu Hubungkan aplikasi di WAG Hub.');

            return self::FAILURE;
        }

        if (! $hub->engine()->isReady()) {
            $this->error('WAG Hub belum siap. Periksa URL, izin engine:use pada token, dan layanan WhatsApp di WAG Hub.');

            return self::FAILURE;
        }

        $this->info('WAG Hub siap. Login WhatsApp melalui QR atau pairing di pengaturan aplikasi.');

        return self::SUCCESS;
    }
}
