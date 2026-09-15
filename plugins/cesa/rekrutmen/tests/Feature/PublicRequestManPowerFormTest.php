<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Jobs\SendNotificationDeliveryJob;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerForm;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Models\RequestManPowerApprovalRequestedNotification;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Webkul\Support\Models\Company;

class PublicRequestManPowerFormTest extends RekrutmenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRekrutmenWhatsAppEngine();
        $this->makeConnectedWhatsAppAccount();
    }

    public function test_public_request_man_power_form_uses_plain_textareas_for_long_text_fields(): void
    {
        app()->setLocale('en');

        $response = $this->get('/man-power');

        $fieldLabels = [
            __('rekrutmen::livewire/public-request-man-power-form.fields.nama_pengaju'),
            __('rekrutmen::livewire/public-request-man-power-form.fields.posisi_dibutuhkan'),
            __('rekrutmen::livewire/public-request-man-power-form.fields.requirements_kualifikasi'),
            __('rekrutmen::livewire/public-request-man-power-form.fields.job_description'),
        ];

        $response
            ->assertOk()
            ->assertDontSee('fi-fo-rich-editor', false);

        foreach ($fieldLabels as $fieldLabel) {
            $response->assertSee(e($fieldLabel), false);
        }
    }

    public function test_public_request_man_power_submission_falls_back_to_today_when_submission_date_state_is_missing(): void
    {
        Notification::fake();

        $company = Company::query()->create([
            'name' => 'PT Cesa Indonesia',
        ]);
        $division = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $company->id,
        ]);

        Livewire::test(PublicRequestManPowerForm::class)
            ->set('data.nama_pengaju', 'Andi Saputra')
            ->set('data.email_address', 'andi@example.com')
            ->set('data.posisi_pengaju', 'HR Manager')
            ->set('data.company_id', $company->id)
            ->set('data.division_id', $division->id)
            ->set('data.status_kebutuhan', 'New Hiring')
            ->set('data.posisi_dibutuhkan', 'Software Engineer')
            ->set('data.level_pekerjaan', 'Staff')
            ->set('data.jumlah_karyawan_dibutuhkan', 1)
            ->set('data.lokasi_penempatan', 'Jakarta')
            ->set('data.estimasi_tanggal_join', '2026-04-01')
            ->set('data.job_description', 'Develop internal systems')
            ->set('data.requirements_kualifikasi', 'PHP, Laravel, SQL')
            ->set('data.keterangan', 'Urgent hiring')
            ->set('data.tanggal_pengajuan', null)
            ->call('submit')
            ->assertHasNoErrors();

        $request = RequestManPower::query()->first();

        $this->assertNotNull($request);
        $this->assertSame(now()->toDateString(), $request->tanggal_pengajuan?->toDateString());
    }

    public function test_public_request_man_power_validation_dispatches_feedback_events(): void
    {
        $company = Company::query()->create([
            'name' => 'PT Cesa Indonesia',
        ]);
        $division = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $company->id,
        ]);

        Livewire::test(PublicRequestManPowerForm::class)
            ->set('data.nama_pengaju', 'Andi Saputra')
            ->set('data.email_address', 'andi@example.com')
            ->set('data.posisi_pengaju', 'HR Manager')
            ->set('data.company_id', $company->id)
            ->set('data.division_id', $division->id)
            ->set('data.status_kebutuhan', 'Replacement')
            ->set('data.posisi_dibutuhkan', 'Software Engineer')
            ->set('data.level_pekerjaan', 'Staff')
            ->set('data.jumlah_karyawan_dibutuhkan', 1)
            ->set('data.lokasi_penempatan', 'Jakarta')
            ->set('data.estimasi_tanggal_join', '2026-04-01')
            ->set('data.job_description', 'Develop internal systems')
            ->set('data.requirements_kualifikasi', 'PHP, Laravel, SQL')
            ->set('data.keterangan', 'Urgent hiring')
            ->call('submit')
            ->assertHasErrors([
                'data.nama_karyawan_replacement',
            ])
            ->assertDispatched('form-errors-presented')
            ->assertDispatched('form-processing-finished');
    }

    public function test_public_request_man_power_requires_email_address(): void
    {
        $company = Company::query()->create([
            'name' => 'PT Cesa Indonesia',
        ]);
        $division = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $company->id,
        ]);

        Livewire::test(PublicRequestManPowerForm::class)
            ->set('data.nama_pengaju', 'Andi Saputra')
            ->set('data.email_address', '')
            ->set('data.posisi_pengaju', 'HR Manager')
            ->set('data.company_id', $company->id)
            ->set('data.division_id', $division->id)
            ->set('data.status_kebutuhan', 'New Hiring')
            ->set('data.posisi_dibutuhkan', 'Software Engineer')
            ->set('data.level_pekerjaan', 'Staff')
            ->set('data.jumlah_karyawan_dibutuhkan', 1)
            ->set('data.lokasi_penempatan', 'Jakarta')
            ->set('data.estimasi_tanggal_join', '2026-04-01')
            ->set('data.job_description', 'Develop internal systems')
            ->set('data.requirements_kualifikasi', 'PHP, Laravel, SQL')
            ->set('data.keterangan', 'Urgent hiring')
            ->call('submit')
            ->assertHasErrors([
                'data.email_address',
            ]);
    }

    public function test_public_request_man_power_submission_ignores_client_supplied_submission_date(): void
    {
        Notification::fake();

        $company = Company::query()->create([
            'name' => 'PT Cesa Indonesia',
        ]);
        $division = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $company->id,
        ]);

        Livewire::test(PublicRequestManPowerForm::class)
            ->set('data.nama_pengaju', 'Andi Saputra')
            ->set('data.email_address', 'andi@example.com')
            ->set('data.posisi_pengaju', 'HR Manager')
            ->set('data.company_id', $company->id)
            ->set('data.division_id', $division->id)
            ->set('data.status_kebutuhan', 'New Hiring')
            ->set('data.posisi_dibutuhkan', 'Software Engineer')
            ->set('data.level_pekerjaan', 'Staff')
            ->set('data.jumlah_karyawan_dibutuhkan', 1)
            ->set('data.lokasi_penempatan', 'Jakarta')
            ->set('data.estimasi_tanggal_join', '2026-04-01')
            ->set('data.job_description', 'Develop internal systems')
            ->set('data.requirements_kualifikasi', 'PHP, Laravel, SQL')
            ->set('data.keterangan', 'Urgent hiring')
            ->set('data.tanggal_pengajuan', '2000-01-01')
            ->call('submit')
            ->assertHasNoErrors();

        $request = RequestManPower::query()->first();

        $this->assertNotNull($request);
        $this->assertSame(now()->toDateString(), $request->tanggal_pengajuan?->toDateString());
    }

    public function test_public_request_man_power_submission_notifies_only_the_first_pending_approver(): void
    {
        Notification::fake();
        Queue::fake();

        config()->set('rekrutmen.notifications.whatsapp.enabled', true);

        config()->set('rekrutmen.notifications.whatsapp.endpoint', 'https://example.com/whatsapp');
        config()->set('rekrutmen.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('rekrutmen.notifications.whatsapp.queue', 'whatsapp');

        $company = Company::query()->create([
            'name' => 'PT Cesa Approval',
        ]);
        $division = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $company->id,
        ]);

        Approver::query()->create([
            'name'           => 'IT Approver',
            'email'          => 'it.approver@example.com',
            'phone'          => '081234567890',
            'title'          => 'HR Manager',
            'approval_order' => 1,
            'division_id'    => $division->id,
            'is_active'      => true,
        ]);

        Approver::query()->create([
            'name'           => 'GM Approver',
            'email'          => 'gm.approver@example.com',
            'phone'          => '081234567891',
            'title'          => 'General Manager',
            'approval_order' => 2,
            'division_id'    => $division->id,
            'is_active'      => true,
        ]);

        Livewire::test(PublicRequestManPowerForm::class)
            ->set('data.nama_pengaju', 'Andi Saputra')
            ->set('data.email_address', 'andi@example.com')
            ->set('data.posisi_pengaju', 'HR Manager')
            ->set('data.company_id', $company->id)
            ->set('data.division_id', $division->id)
            ->set('data.status_kebutuhan', 'New Hiring')
            ->set('data.posisi_dibutuhkan', 'Software Engineer')
            ->set('data.level_pekerjaan', 'Staff')
            ->set('data.jumlah_karyawan_dibutuhkan', 1)
            ->set('data.lokasi_penempatan', 'Jakarta')
            ->set('data.estimasi_tanggal_join', '2026-04-01')
            ->set('data.job_description', 'Develop internal systems')
            ->set('data.requirements_kualifikasi', 'PHP, Laravel, SQL')
            ->set('data.keterangan', 'Urgent hiring')
            ->call('submit')
            ->assertHasNoErrors();

        Notification::assertSentOnDemandTimes(RequestManPowerApprovalRequestedNotification::class, 1);

        Notification::assertSentOnDemand(RequestManPowerApprovalRequestedNotification::class, function (
            RequestManPowerApprovalRequestedNotification $notification,
            array $channels,
            object $notifiable
        ): bool {
            return in_array('mail', $channels, true)
                && ($notifiable->routes['mail'] ?? null) === 'it.approver@example.com';
        });

        Queue::assertPushed(SendNotificationDeliveryJob::class, function (SendNotificationDeliveryJob $job): bool {
            return $job->queue === 'whatsapp';
        });
    }
}
