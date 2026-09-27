<?php

use App\Filament\Admin\Auth\PhoneLogin;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WhatsApp\WagHubClient;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    // Setiap tes memakai koneksi SQLite :memory: baru, jadi migrasi harus diulang.
    Artisan::call('migrate:fresh', ['--force' => true]);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    config([
        'wag.url'            => 'https://hub.test',
        'wag.token'          => 'app-token',
        'wag.otp.expires_in' => 300,
    ]);
});

function fakeHub(): void
{
    Http::fake([
        'https://hub.test/*' => Http::response(['data' => ['id' => '22222222-2222-4222-8222-222222222222', 'status' => 'queued']], 202),
    ]);
}

/**
 * Semua kode OTP 6 digit yang terkirim ke WAG Hub, sesuai urutan pengiriman.
 *
 * @return array<int, string>
 */
function capturedOtpCodes(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/api/v1/messages'))
        ->map(fn (array $pair): ?string => otpFromRequest($pair[0]))
        ->filter(fn (?string $otp): bool => $otp !== null)
        ->values()
        ->all();
}

function otpFromRequest(Request $request): ?string
{
    preg_match('/\b(\d{6})\b/', (string) data_get($request->data(), 'message.text', ''), $matches);

    return $matches[1] ?? null;
}

it('sends an OTP through WAG Hub for a registered active user', function () {
    fakeHub();
    $user = User::factory()->create(['phone' => '081234567890', 'is_active' => true]);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '+62 812-3456-7890')
        ->call('send')
        ->assertHasNoErrors();

    $otpCodes = capturedOtpCodes();

    expect($otpCodes)->toHaveCount(1)
        ->and($otpCodes[0])->toMatch('/^\d{6}$/');

    $otpRecord = WhatsappOtp::query()->sole();

    expect($otpRecord->whatsapp_number)->toBe('6281234567890')
        ->and($otpRecord->user_id)->toBe($user->id)
        ->and($otpRecord->purpose)->toBe(WhatsappOtp::PURPOSE_LOGIN)
        ->and($otpRecord->verified_at)->toBeNull()
        ->and($otpRecord->expires_at->isFuture())->toBeTrue()
        ->and($otpRecord->otp_hash)->not->toBe($otpCodes[0]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/messages'
        && $request['recipient']['value'] === '6281234567890'
        && str_contains((string) $request['message']['text'], $otpCodes[0])
        && $request['purpose'] === 'authentication'
        && $request->hasHeader('Idempotency-Key', 'whatsapp-login-otp-'.$otpRecord->id));
});

it('redirects to the email login page when the WhatsApp gateway is not configured', function () {
    config(['wag.url' => '', 'wag.token' => '']);

    expect(app(WagHubClient::class)->isConfigured())->toBeFalse();

    Livewire::test(PhoneLogin::class)
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

it('rejects numbers that are not registered for WhatsApp login', function () {
    fakeHub();
    User::factory()->create(['phone' => '089876543210']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send')
        ->assertHasErrors(['data.whatsapp_number' => 'No. HP belum terdaftar untuk login WhatsApp.']);

    expect(WhatsappOtp::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects inactive accounts without revealing their status', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890', 'is_active' => false]);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send')
        ->assertHasErrors(['data.whatsapp_number' => 'No. HP belum terdaftar untuk login WhatsApp.']);

    expect(WhatsappOtp::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects ambiguous numbers shared by more than one account', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890']);
    User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send')
        ->assertHasErrors(['data.whatsapp_number']);

    expect(WhatsappOtp::query()->count())->toBe(0);
});

it('rejects numbers that are not valid indonesian whatsapp numbers', function () {
    fakeHub();

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '12345')
        ->call('send')
        ->assertHasErrors(['data.whatsapp_number' => 'Nomor WhatsApp tidak valid.']);

    Http::assertNothingSent();
});

it('logs the user in when the correct OTP is verified', function () {
    fakeHub();
    $user = User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send')
        ->set('data.otp', capturedOtpCodes()[0])
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($user->fresh());

    expect(WhatsappOtp::query()->sole()->verified_at)->not->toBeNull();
});

it('rejects a wrong OTP and counts the attempt', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send');

    $wrongOtp = capturedOtpCodes()[0] === '111111' ? '222222' : '111111';

    Livewire::test(PhoneLogin::class, ['awaitingOtp' => true])
        ->set('data.whatsapp_number', '6281234567890')
        ->set('data.otp', $wrongOtp)
        ->call('verify')
        ->assertHasErrors(['data.otp' => 'OTP tidak valid atau sudah kedaluwarsa.']);

    $this->assertGuest();

    expect(WhatsappOtp::query()->sole()->attempt_count)->toBe(1);
});

it('invalidates the previous OTP when a new one is issued', function () {
    fakeHub();
    $user = User::factory()->create(['phone' => '081234567890']);

    $component = Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send');

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send');

    [$firstOtp, $secondOtp] = capturedOtpCodes();

    expect($firstOtp)->not->toBe($secondOtp);

    $component
        ->set('data.otp', $firstOtp)
        ->call('verify')
        ->assertHasErrors(['data.otp']);

    $this->assertGuest();

    $component
        ->set('data.otp', $secondOtp)
        ->call('verify')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user->fresh());
});

it('expires the OTP after too many failed attempts', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send');

    $otp = capturedOtpCodes()[0];
    $wrongOtp = $otp === '999999' ? '000000' : '999999';

    foreach (range(1, 5) as $attempt) {
        Livewire::test(PhoneLogin::class, ['awaitingOtp' => true])
            ->set('data.whatsapp_number', '6281234567890')
            ->set('data.otp', $wrongOtp)
            ->call('verify');
    }

    $otpRecord = WhatsappOtp::query()->sole();

    expect($otpRecord->attempt_count)->toBe(5)
        ->and($otpRecord->expires_at->isPast())->toBeTrue();

    $this->assertGuest();
});

it('rejects a verification after the OTP has expired', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send');

    $otp = capturedOtpCodes()[0];
    WhatsappOtp::query()->sole()->forceFill(['expires_at' => now()->subMinute()])->save();

    Livewire::test(PhoneLogin::class, ['awaitingOtp' => true])
        ->set('data.whatsapp_number', '6281234567890')
        ->set('data.otp', $otp)
        ->call('verify')
        ->assertHasErrors(['data.otp']);

    $this->assertGuest();
});

it('reports a gateway failure instead of pretending the OTP was sent', function () {
    Http::fake(['https://hub.test/*' => Http::response(['message' => 'Gateway penuh.'], 503)]);

    User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send')
        ->assertHasErrors(['data.whatsapp_number' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.']);

    expect(WhatsappOtp::query()->sole()->expires_at->isPast())->toBeTrue();
});

it('stores user phone numbers in the normalized local format regardless of input format', function () {
    $international = User::factory()->create(['phone' => '+62 812-3456-789']);
    $noCountryCode = User::factory()->create(['phone' => '6281234567890']);
    $alreadyLocal = User::factory()->create(['phone' => '081234567890']);
    $blank = User::factory()->create(['phone' => null]);

    expect($international->refresh()->phone)->toBe('08123456789')
        ->and($noCountryCode->refresh()->phone)->toBe('081234567890')
        ->and($alreadyLocal->refresh()->phone)->toBe('081234567890')
        ->and($blank->refresh()->phone)->toBeNull();
});

it('finds users whose phone was saved in an international format', function () {
    fakeHub();
    User::factory()->create(['phone' => '+62 812-3456-789']);

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '628123456789')
        ->call('send')
        ->assertHasNoErrors();

    expect(WhatsappOtp::query()->sole()->whatsapp_number)->toBe('628123456789');
});

it('reports an invalid number consistently when verifying', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890']);

    Livewire::test(PhoneLogin::class, ['awaitingOtp' => true])
        ->set('data.whatsapp_number', '12345')
        ->set('data.otp', '123456')
        ->call('verify')
        ->assertHasErrors(['data.whatsapp_number' => 'Nomor WhatsApp tidak valid.']);
});

it('throttles repeated OTP requests for the same number', function () {
    fakeHub();
    User::factory()->create(['phone' => '081234567890']);

    foreach (range(1, 3) as $attempt) {
        Livewire::test(PhoneLogin::class)
            ->set('data.whatsapp_number', '081234567890')
            ->call('send')
            ->assertHasNoErrors();
    }

    Livewire::test(PhoneLogin::class)
        ->set('data.whatsapp_number', '081234567890')
        ->call('send')
        ->assertHasErrors(['data.whatsapp_number' => 'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.']);

    expect(RateLimiter::tooManyAttempts('whatsapp-otp:filament:number:5m:'.hash('sha256', '6281234567890'), 3))->toBeTrue();
});
