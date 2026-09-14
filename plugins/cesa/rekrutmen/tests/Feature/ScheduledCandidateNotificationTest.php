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

class ScheduledCandidateNotificationTest extends RekrutmenTestCase
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

    public function test_can_schedule_single_candidate_notification(): void
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
            'title'                 => 'Backend Engineer',
            'slug'                  => 'backend-eng-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'location'              => 'Cirebon',
            'is_published'          => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Ahmad Sanusi',
            'email'            => 'ahmad@example.com',
            'whatsapp_number'  => '081299998888',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $scheduledTime = Carbon::now()->addDays(2)->format('Y-m-d H:i:s');

        $response = $this->postJson("/rekrutmen/api/applications/{$app->id}/send-notification", [
            'subject'         => 'Undangan Interview {nama_pelamar}',
            'body_message'    => 'Halo {nama_pelamar}, selamat Anda diundang untuk {posisi}.',
            'channels'        => ['email', 'whatsapp'],
            'send_type'       => 'scheduled',
            'scheduled_at'    => $scheduledTime,
            'venue_or_method' => 'Google Meet',
            'schedule'        => 'Senin, 10:00 WIB',
        ]);

        $response->assertAccepted();
        $response->assertJson([
            'success'   => true,
            'scheduled' => true,
        ]);

        $this->assertDatabaseHas('rekrutmen_scheduled_notifications', [
            'subject' => 'Undangan Interview {nama_pelamar}',
            'status'  => ScheduledNotification::STATUS_PENDING,
        ]);

        // When scheduled, immediate mail or HTTP shouldn't have been sent yet
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_can_schedule_bulk_candidate_notification(): void
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
            'title'                 => 'UI/UX Designer',
            'slug'                  => 'uiux-des-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'location'              => 'Cirebon',
            'is_published'          => true,
        ]);

        $app1 = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Siti Aminah',
            'email'            => 'siti@example.com',
            'whatsapp_number'  => '081211112222',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $app2 = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Bambang Sudiro',
            'email'            => 'bambang@example.com',
            'whatsapp_number'  => '081233334444',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $scheduledTime = Carbon::now()->addDays(3)->format('Y-m-d H:i:s');

        $response = $this->postJson('/rekrutmen/api/applications/bulk-send-notification', [
            'application_ids' => [$app1->id, $app2->id],
            'subject'         => 'Undangan Tes Psikotes Online',
            'body_message'    => 'Halo {nama_pelamar}, Anda dijadwalkan tes online.',
            'channels'        => ['email', 'whatsapp'],
            'send_type'       => 'scheduled',
            'scheduled_at'    => $scheduledTime,
        ]);

        $response->assertAccepted();
        $response->assertJson([
            'success'   => true,
            'scheduled' => true,
        ]);

        $scheduledRecord = ScheduledNotification::latest('id')->first();
        $this->assertNotNull($scheduledRecord);
        $this->assertSame(ScheduledNotification::STATUS_PENDING, $scheduledRecord->status);
        $this->assertCount(2, $scheduledRecord->application_ids);
    }

    public function test_artisan_process_scheduled_notifications_sends_due_records(): void
    {
        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $stage = RekrutmenStage::firstOrCreate([
            'id'                    => 1,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Screening CV',
            'order_column' => 1,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'Frontend Developer',
            'slug'                  => 'fe-dev-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'location'              => 'Cirebon',
            'is_published'          => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Dewi Sartika',
            'email'            => 'dewi@example.com',
            'whatsapp_number'  => '081277778888',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        // Create a scheduled notification whose time has already passed (due)
        $scheduled = ScheduledNotification::create([
            'application_ids' => [$app->id],
            'channels'        => ['email', 'whatsapp'],
            'subject'         => 'Panggilan Interview {nama_pelamar}',
            'body_message'    => 'Selamat {nama_pelamar}, Anda lolos ke tahap interview.',
            'scheduled_at'    => Carbon::now()->subMinute(),
            'status'          => ScheduledNotification::STATUS_PENDING,
        ]);

        $this->artisan('rekrutmen:process-scheduled-notifications')
            ->expectsOutput('Checking for due scheduled candidate notifications...')
            ->expectsOutput('Processed 1 scheduled notification batch(es).')
            ->assertSuccessful();

        Http::assertNothingSent();
        app(ScheduledNotificationService::class)->executeScheduled($scheduled, true);
        $scheduled->refresh();
        $this->assertSame(ScheduledNotification::STATUS_SENT, $scheduled->status);
        $this->assertNotNull($scheduled->sent_at);
        $this->assertSame(1, $scheduled->results['stats']['email_success'] ?? 0);
        $this->assertSame(1, $scheduled->results['stats']['whatsapp_success'] ?? 0);
    }

    public function test_auto_advances_stage_when_invitation_is_sent(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['resource_permission' => 'global'])->save();
        $user->givePermissionTo(Permission::findOrCreate('update_rekrutmen_job::application', 'web'));
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $stageScreening = RekrutmenStage::firstOrCreate([
            'id'                    => 1,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Screening CV',
            'order_column' => 1,
        ]);

        $stageInterview = RekrutmenStage::firstOrCreate([
            'id'                    => 2,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Interview HR',
            'order_column' => 2,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'Frontend Developer',
            'slug'                  => 'frontend-dev-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Budi Setiawan',
            'email'            => 'budi@example.com',
            'whatsapp_number'  => '081234567890',
            'current_stage_id' => $stageScreening->id,
            'status'           => 'in_progress',
        ]);

        $this->assertSame($stageScreening->id, $app->current_stage_id);

        // Invite for interview
        $response = $this->postJson("/rekrutmen/api/applications/{$app->id}/send-notification", [
            'subject'      => 'Undangan Wawancara',
            'body_message' => 'Selamat Anda diundang wawancara.',
            'channels'     => ['email', 'whatsapp'],
            'template_key' => 'interview',
            'send_type'    => 'immediate',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success'   => true,
            'new_stage' => [
                'id'   => $stageInterview->id,
                'name' => 'Interview HR',
            ],
        ]);

        $app->refresh();
        $this->assertSame($stageInterview->id, $app->current_stage_id);

        $stageUser = RekrutmenStage::firstOrCreate([
            'id'                    => 17,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Interview User',
            'order_column' => 5,
        ]);

        $responseUser = $this->postJson("/rekrutmen/api/applications/{$app->id}/send-notification", [
            'subject'      => 'Undangan Wawancara User',
            'body_message' => 'Selamat Anda diundang wawancara user.',
            'channels'     => ['email', 'whatsapp'],
            'template_key' => 'interview_user',
            'send_type'    => 'immediate',
        ]);
        $responseUser->assertOk();
        $app->refresh();
        $this->assertSame($stageUser->id, $app->current_stage_id);
    }
}
