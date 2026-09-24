<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

class RequestManPowerPendingBadgeTest extends RekrutmenTestCase
{
    protected function setUp(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }

        parent::setUp();
    }

    public function test_requests_api_keeps_raw_pending_status_when_indonesian_label_is_menunggu(): void
    {
        Notification::fake();
        app()->setLocale('id');

        $user = User::factory()->create([
            'is_active'           => true,
            'resource_permission' => PermissionType::INDIVIDUAL,
        ]);
        $user->givePermissionTo(Permission::findOrCreate('view_any_rekrutmen_request::man::power', 'web'));
        $this->actingAs($user);

        $this->createRequest(RequestManPowerStatus::PENDING, 'Staff Menunggu A');
        $this->createRequest(RequestManPowerStatus::PENDING, 'Staff Menunggu B');
        $this->createRequest(RequestManPowerStatus::APPROVED, 'Staff Disetujui');

        $response = $this->getJson('/rekrutmen/api/requests');

        $response->assertOk();

        $rows = collect($response->json('data'));
        $pending = $rows->where('raw_status', 'pending');

        $this->assertCount(2, $pending);
        $pending->each(function (array $row): void {
            $this->assertSame('Menunggu', $row['status']);
            $this->assertSame('Menunggu', $row['approval_status']);
        });
        $this->assertCount(1, $rows->where('raw_status', 'approved'));
    }

    private function createRequest(RequestManPowerStatus $status, string $position): RequestManPower
    {
        return RequestManPower::query()->create([
            'email_address'              => 'requester-'.md5($position).'@example.com',
            'nama_pengaju'               => 'Andi Saputra',
            'posisi_pengaju'             => 'HR Manager',
            'tanggal_pengajuan'          => '2026-03-02',
            'posisi_dibutuhkan'          => $position,
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'IT',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => '2026-04-01',
            'requirements_kualifikasi'   => 'PHP, Laravel, SQL',
            'job_description'            => 'Develop internal systems',
            'status'                     => $status,
        ]);
    }
}
