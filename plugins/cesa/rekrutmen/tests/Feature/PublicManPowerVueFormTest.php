<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Webkul\Support\Models\Company;

class PublicManPowerVueFormTest extends RekrutmenTestCase
{
    public function test_can_render_public_man_power_form_page(): void
    {
        $response = $this->get('/man-power');

        $response->assertOk()
            ->assertSee('pf-header-card', false)
            ->assertSee('wire:model', false)
            ->assertDontSee('window.__MANPOWER_CONFIG__', false);
    }

    public function test_can_submit_public_man_power_via_api_and_persist_record(): void
    {
        $company = Company::query()->create([
            'name'      => 'PT Complete Selular',
            'is_active' => true,
        ]);

        $division = Division::query()->create([
            'name'       => 'Teknologi Informasi',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $payload = [
            'nama_pengaju'               => 'Rian Firmansyah',
            'email_address'              => 'rian@completeselular.com',
            'posisi_pengaju'             => 'Head of IT',
            'company_id'                 => $company->id,
            'division_id'                => $division->id,
            'status_kebutuhan'           => 'New Hiring',
            'posisi_dibutuhkan'          => 'Senior Backend Engineer',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 2,
            'lokasi_penempatan'          => 'Cirebon Head Office',
            'estimasi_tanggal_join'      => '2026-10-01',
            'job_description'            => 'Mengembangkan sistem internal berbasis Laravel dan Vue.',
            'requirements_kualifikasi'   => 'Pengalaman 3 tahun di Laravel, PHP 8, dan Vue 3.',
            'keterangan'                 => 'Kebutuhan mendesak untuk ekspansi cabang baru.',
        ];

        $response = $this->postJson('/man-power/api/submit', $payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $rmp = RequestManPower::query()->first();
        $this->assertNotNull($rmp);
        $this->assertSame('Rian Firmansyah', $rmp->nama_pengaju);
        $this->assertSame('rian@completeselular.com', $rmp->email_address);
        $this->assertSame('Head of IT', $rmp->posisi_pengaju);
        $this->assertSame($company->id, $rmp->company_id);
        $this->assertSame($division->id, $rmp->division_id);
        $this->assertSame('Senior Backend Engineer', $rmp->posisi_dibutuhkan);
        $this->assertSame(2, $rmp->jumlah_karyawan_dibutuhkan);
        $this->assertSame('Cirebon Head Office', $rmp->lokasi_penempatan);
        $this->assertSame(now()->toDateString(), $rmp->tanggal_pengajuan?->toDateString());
        $this->assertNull($rmp->nama_karyawan_replacement);

        $response->assertJson([
            'redirect_url'      => $rmp->getPublicProgressUrl(),
            'recent_submission' => [
                'posisi_dibutuhkan' => 'Senior Backend Engineer',
                'nama_pengaju'      => 'Rian Firmansyah',
            ],
        ]);
    }

    public function test_public_man_power_submission_requires_replacement_name_when_status_is_replacement(): void
    {
        $company = Company::query()->create([
            'name'      => 'PT Complete Selular',
            'is_active' => true,
        ]);

        $division = Division::query()->create([
            'name'       => 'Operasional',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $payload = [
            'nama_pengaju'               => 'Rian Firmansyah',
            'email_address'              => 'rian@completeselular.com',
            'posisi_pengaju'             => 'Head of Operations',
            'company_id'                 => $company->id,
            'division_id'                => $division->id,
            'status_kebutuhan'           => 'Replacement',
            'nama_karyawan_replacement'  => '',
            'posisi_dibutuhkan'          => 'Store Leader',
            'level_pekerjaan'            => 'Leader',
            'jumlah_karyawan_dibutuhkan' => 1,
            'lokasi_penempatan'          => 'Cirebon',
            'estimasi_tanggal_join'      => '2026-10-01',
            'job_description'            => 'Memimpin operasional toko harian.',
            'requirements_kualifikasi'   => 'Pengalaman retail 2 tahun.',
        ];

        $response = $this->postJson('/man-power/api/submit', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nama_karyawan_replacement']);

        // Now supply replacement name
        $payload['nama_karyawan_replacement'] = 'Ahmad Syahrul';
        $successResponse = $this->postJson('/man-power/api/submit', $payload);

        $successResponse->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('rekrutmen_request_man_powers', [
            'nama_karyawan_replacement' => 'Ahmad Syahrul',
        ]);
    }

    public function test_public_man_power_submission_validates_required_fields(): void
    {
        $response = $this->postJson('/man-power/api/submit', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'nama_pengaju',
                'email_address',
                'posisi_pengaju',
                'company_id',
                'division_id',
                'status_kebutuhan',
                'posisi_dibutuhkan',
                'level_pekerjaan',
                'jumlah_karyawan_dibutuhkan',
                'lokasi_penempatan',
                'estimasi_tanggal_join',
                'job_description',
                'requirements_kualifikasi',
            ]);
    }
}
