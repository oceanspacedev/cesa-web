<?php

use App\Models\User;
use App\Models\WhatsappOtp;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    // Setiap tes memakai koneksi SQLite :memory: baru, jadi migrasi harus diulang.
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('prunes dead OTP rows while keeping active ones', function () {
    $user = User::factory()->create(['phone' => '081234567890']);

    $active = WhatsappOtp::query()->create([
        'user_id'         => $user->id,
        'whatsapp_number' => '6281234567890',
        'purpose'         => WhatsappOtp::PURPOSE_LOGIN,
        'otp_hash'        => 'hash',
        'expires_at'      => now()->addMinutes(5),
        'attempt_count'   => 0,
    ]);

    $expired = WhatsappOtp::query()->create([
        'user_id'         => $user->id,
        'whatsapp_number' => '6281234567890',
        'purpose'         => WhatsappOtp::PURPOSE_LOGIN,
        'otp_hash'        => 'hash',
        'expires_at'      => now()->subDays(2),
        'attempt_count'   => 5,
    ]);

    $verified = WhatsappOtp::query()->create([
        'user_id'         => $user->id,
        'whatsapp_number' => '6281234567890',
        'purpose'         => WhatsappOtp::PURPOSE_LOGIN,
        'otp_hash'        => 'hash',
        'expires_at'      => now()->subDays(2),
        'verified_at'     => now()->subDays(2),
        'attempt_count'   => 1,
    ]);

    Artisan::call('model:prune', ['--model' => WhatsappOtp::class]);

    expect(WhatsappOtp::query()->whereKey($active->id)->exists())->toBeTrue()
        ->and(WhatsappOtp::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(WhatsappOtp::query()->whereKey($verified->id)->exists())->toBeFalse();
});
