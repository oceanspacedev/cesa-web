<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Carbon\Carbon;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Models\User;

class BulkCandidateCustomScheduleNotificationTest extends RekrutmenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['rekrutmen.notifications.whatsapp.throttle.enabled' => false]);

        Mail::fake();
        $this->fakeRekrutmenWhatsAppEngine();
        $this->makeConnectedWhatsAppAccount();
    }

    public function test_bulk_send_notification_with_individual_schedules(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['resource_permission' => 'global'])->save();
        $user->givePermissionTo(Permission::findOrCreate('update_rekrutmen_job::application', 'web'));
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $stage = RekrutmenStage::firstOrCreate([
            'id'                    => 1,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Screening CV',
            'order_column' => 1,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'HR Staff',
            'slug'                  => 'hr-staff-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'location'              => 'Cirebon',
            'is_published'          => true,
        ]);

        $reza = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Reza Maulana',
            'email'            => 'reza@example.com',
            'whatsapp_number'  => '081211112222',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $salsa = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Salsabila Putri',
            'email'            => 'salsa@example.com',
            'whatsapp_number'  => '081233334444',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $response = $this->postJson('/rekrutmen/api/applications/bulk-send-notification', [
            'application_ids'     => [$reza->id, $salsa->id],
            'channels'            => ['email', 'whatsapp'],
            'subject'             => 'Undangan Interview {nama_pelamar} - {jadwal}',
            'body_message'        => 'Halo {nama_pelamar}, jadwal interview Anda dijadwalkan pada {jadwal}.',
            'schedule'            => 'Jadwal Default 10:00 WIB',
            'candidate_schedules' => [
                $reza->id => [
                    'schedule'        => 'Senin, 10 Maret 2026 pukul 08:00 - 09:00 WIB',
                    'venue_or_method' => 'Ruang Interview 1',
                ],
                $salsa->id => [
                    'schedule'        => 'Senin, 10 Maret 2026 pukul 09:00 - 10:00 WIB',
                    'venue_or_method' => 'Ruang Interview 2',
                ],
            ],
        ]);

        $response->assertAccepted();
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonPath('stats.email_pending', 2);
        $response->assertJsonPath('stats.whatsapp_pending', 2);
        Http::assertNothingSent();
        $batch = ScheduledNotification::query()->findOrFail($response->json('batch_id'));
        app(ScheduledNotificationService::class)->executeScheduled($batch, true);

        // Verify WhatsApp gateway was called with distinct messages containing individual schedules
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = $data['text'] ?? '';

            return str_contains((string) ($data['phone'] ?? ''), '81211112222')
                && str_contains($msg, '08:00 - 09:00 WIB')
                && str_contains($msg, 'Ruang Interview 1');
        });

        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = $data['text'] ?? '';

            return str_contains((string) ($data['phone'] ?? ''), '81233334444')
                && str_contains($msg, '09:00 - 10:00 WIB')
                && str_contains($msg, 'Ruang Interview 2');
        });
    }

    public function test_bulk_scheduled_notification_with_individual_schedules(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['resource_permission' => 'global'])->save();
        $user->givePermissionTo(Permission::findOrCreate('update_rekrutmen_job::application', 'web'));
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $stage = RekrutmenStage::firstOrCreate([
            'id'                    => 1,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Screening CV',
            'order_column' => 1,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'HR Staff',
            'slug'                  => 'hr-staff-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'location'              => 'Cirebon',
            'is_published'          => true,
        ]);

        $reza = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Reza Maulana',
            'email'            => 'reza@example.com',
            'whatsapp_number'  => '081211112222',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $salsa = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Salsabila Putri',
            'email'            => 'salsa@example.com',
            'whatsapp_number'  => '081233334444',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $scheduledTime = Carbon::now()->addDays(2)->format('Y-m-d H:i:s');

        $response = $this->postJson('/rekrutmen/api/applications/bulk-send-notification', [
            'application_ids'     => [$reza->id, $salsa->id],
            'channels'            => ['email', 'whatsapp'],
            'send_type'           => 'scheduled',
            'scheduled_at'        => $scheduledTime,
            'subject'             => 'Undangan Interview {nama_pelamar} - {jadwal}',
            'body_message'        => 'Halo {nama_pelamar}, jadwal interview: {jadwal}',
            'schedule'            => 'Jadwal Default 10:00 WIB',
            'candidate_schedules' => [
                $reza->id => [
                    'schedule' => '08:00 - 09:00 WIB',
                ],
                $salsa->id => [
                    'schedule' => '09:00 - 10:00 WIB',
                ],
            ],
        ]);

        $response->assertAccepted();
        $response->assertJson([
            'success'   => true,
            'scheduled' => true,
        ]);

        $notification = ScheduledNotification::where('status', ScheduledNotification::STATUS_PENDING)->latest('id')->first();
        $this->assertNotNull($notification);
        $this->assertIsArray($notification->candidate_schedules);
        $this->assertEquals('08:00 - 09:00 WIB', $notification->candidate_schedules[$reza->id]['schedule']);
        $this->assertEquals('09:00 - 10:00 WIB', $notification->candidate_schedules[$salsa->id]['schedule']);

        // Execute scheduled notification
        $this->travelTo(Carbon::parse($scheduledTime)->addSecond());
        $results = app(ScheduledNotificationService::class)->executeScheduled($notification, true);

        $this->assertEquals(2, $results['stats']['email_success'] ?? 0);
        $this->assertEquals(2, $results['stats']['whatsapp_success'] ?? 0);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = $data['text'] ?? '';

            return str_contains((string) ($data['phone'] ?? ''), '81211112222')
                && str_contains($msg, '08:00 - 09:00 WIB');
        });

        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = $data['text'] ?? '';

            return str_contains((string) ($data['phone'] ?? ''), '81233334444')
                && str_contains($msg, '09:00 - 10:00 WIB');
        });
    }
}
