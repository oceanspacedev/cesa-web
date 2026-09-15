<?php

namespace Cesa\Rekrutmen\Database\Seeders;

use Cesa\Rekrutmen\Models\NotificationDelivery;
use Illuminate\Database\Seeder;

class NotificationDeliverySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        NotificationDelivery::factory()->sent()->create([
            'recipient_name' => 'Contoh Riwayat Rekrutmen',
            'error_message'  => 'Data demonstrasi; tidak ada pesan yang dikirim.',
        ]);
    }
}
