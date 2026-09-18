<?php

use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    Notification::fake();
    Http::preventStrayRequests();
    config(['rekrutmen.security.recaptcha.enabled' => false]);

    $this->company = Company::factory()->create(['is_active' => true, 'currency_id' => null]);
    $this->division = Division::query()->create([
        'name'       => 'Teknologi Informasi',
        'company_id' => $this->company->id,
        'is_active'  => true,
    ]);

    $this->payload = [
        'nama_pengaju'               => 'Rian Firmansyah',
        'email_address'              => 'rian@example.com',
        'posisi_pengaju'             => 'Head of IT',
        'company_id'                 => $this->company->id,
        'division_id'                => $this->division->id,
        'status_kebutuhan'           => 'New Hiring',
        'posisi_dibutuhkan'          => 'Backend Engineer',
        'level_pekerjaan'            => 'Staff',
        'jumlah_karyawan_dibutuhkan' => 2,
        'lokasi_penempatan'          => 'Cirebon',
        'estimasi_tanggal_join'      => now()->addMonth()->toDateString(),
        'job_description'            => 'Mengembangkan sistem internal.',
        'requirements_kualifikasi'   => 'Pengalaman Laravel.',
    ];
});

it('ignores workflow attributes supplied by anonymous manpower applicants', function (): void {
    $responseId = (string) Str::uuid();

    $this->postJson('/man-power/api/submit', [
        ...$this->payload,
        'status'             => 'approved',
        'approved_by'        => 987654,
        'job_posting_id'     => 987654,
        'status_response_id' => $responseId,
        'hold_reason'        => 'Injected workflow state',
        'held_at'            => now()->toDateTimeString(),
        'tanggal_pengajuan'  => '2000-01-01',
    ])->assertOk()->assertJson(['success' => true]);

    $request = RequestManPower::query()->sole();

    expect($request->status)->toBe(RequestManPowerStatus::PENDING)
        ->and($request->approved_by)->toBeNull()
        ->and($request->job_posting_id)->toBeNull()
        ->and($request->status_response_id)->not->toBe($responseId)
        ->and($request->hold_reason)->toBeNull()
        ->and($request->held_at)->toBeNull()
        ->and($request->tanggal_pengajuan->toDateString())->toBe(now()->toDateString());
});

it('rejects manpower statuses that cannot be stored as enum values', function (): void {
    $this->postJson('/man-power/api/submit', [
        ...$this->payload,
        'status_kebutuhan'          => 'REPLACEMENT',
        'nama_karyawan_replacement' => 'Previous Employee',
    ])->assertUnprocessable()->assertJsonValidationErrors('status_kebutuhan');

    expect(RequestManPower::query()->count())->toBe(0);
});

it('rejects a division belonging to another company', function (): void {
    $otherCompany = Company::factory()->create(['is_active' => true, 'currency_id' => null]);

    $this->postJson('/man-power/api/submit', [
        ...$this->payload,
        'company_id' => $otherCompany->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('division_id');

    expect(RequestManPower::query()->count())->toBe(0);
});

it('rejects inactive or deleted companies and divisions', function (string $target, string $state): void {
    $record = $this->{$target};

    if ($state === 'deleted') {
        $record->delete();
    } else {
        $record->update(['is_active' => false]);
    }

    $this->postJson('/man-power/api/submit', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors($target.'_id');

    expect(RequestManPower::query()->count())->toBe(0);
})->with([
    'inactive company'  => ['company', 'inactive'],
    'deleted company'   => ['company', 'deleted'],
    'inactive division' => ['division', 'inactive'],
    'deleted division'  => ['division', 'deleted'],
]);

it('requires and verifies recaptcha on the public manpower endpoint', function (): void {
    config([
        'rekrutmen.security.recaptcha.enabled'    => true,
        'rekrutmen.security.recaptcha.site_key'   => 'test-site-key',
        'rekrutmen.security.recaptcha.secret_key' => 'test-secret-key',
    ]);

    $this->postJson('/man-power/api/submit', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('recaptcha_token');

    Http::fake(['www.google.com/recaptcha/api/siteverify' => Http::response(['success' => false])]);

    $this->postJson('/man-power/api/submit', [...$this->payload, 'recaptcha_token' => 'invalid-token'])
        ->assertUnprocessable()->assertJsonValidationErrors('recaptcha_token');

    expect(RequestManPower::query()->count())->toBe(0);
});
