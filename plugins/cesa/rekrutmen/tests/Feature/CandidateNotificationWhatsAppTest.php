<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Services\CandidateWhatsAppNotifier;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Models\User;

class CandidateNotificationWhatsAppTest extends RekrutmenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['rekrutmen.notifications.whatsapp.throttle.enabled' => false]);

        $this->fakeRekrutmenWhatsAppEngine();
        $this->makeConnectedWhatsAppAccount();
    }

    public function test_candidate_whatsapp_notifier_normalizes_phone_and_builds_message(): void
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
            'title'                 => 'Web Developer',
            'slug'                  => 'web-dev-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'location'              => 'Cirebon',
            'is_published'          => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Budi Pratama',
            'email'            => 'budi@example.com',
            'whatsapp_number'  => '081234567890',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $notifier = app(CandidateWhatsAppNotifier::class);
        $res = $notifier->send($app, [
            'subject'         => 'Undangan Psikotes',
            'body_message'    => 'Selamat, Anda diundang ke tahap psikotes.',
            'schedule'        => 'Besok jam 09:00 WIB',
            'venue_or_method' => 'Online',
            'action_url'      => 'https://example.com/test',
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('6281234567890', $res['phone']);

        Http::assertSent(function ($request) use ($app) {
            $data = $request->data();

            return str_contains($request->url(), '/sessions/')
                && str_contains($request->url(), '/send')
                && ($data['phone'] ?? null) === '6281234567890'
                && str_contains((string) ($data['text'] ?? ''), $app->full_name)
                && str_contains((string) ($data['text'] ?? ''), 'Web Developer');
        });
    }

    public function test_can_send_single_candidate_notification_via_api(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);
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
            'title'                 => 'HR Specialist',
            'slug'                  => 'hr-specialist-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Siti Rahma',
            'email'            => 'siti@example.com',
            'whatsapp_number'  => '085712345678',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $response = $this->postJson("/rekrutmen/api/applications/{$app->id}/send-notification", [
            'channels'     => ['whatsapp', 'email'],
            'subject'      => 'Undangan Wawancara untuk {nama_pelamar}',
            'body_message' => 'Halo {nama_pelamar}, Anda lolos ke tahap interview.',
            'schedule'     => 'Senin, 10:00 WIB',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertTrue($response->json('results.whatsapp.success'));
        $this->assertTrue($response->json('results.email.success'));
    }

    public function test_can_send_bulk_candidate_notification_via_api(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);
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
            'title'                 => 'Staff Admin',
            'slug'                  => 'staff-admin-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $app1 = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Kandidat Satu',
            'email'            => 'kandidat1@example.com',
            'whatsapp_number'  => '08111111111',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $app2 = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Kandidat Dua',
            'email'            => 'kandidat2@example.com',
            'whatsapp_number'  => '08222222222',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $response = $this->postJson('/rekrutmen/api/applications/bulk-send-notification', [
            'application_ids' => [$app1->id, $app2->id],
            'channels'        => ['whatsapp', 'email'],
            'subject'         => 'Undangan Tes Online untuk {nama_pelamar}',
            'body_message'    => 'Halo {nama_pelamar}, selamat mengikuti seleksi posisi {posisi}.',
            'schedule'        => 'Batas: 3 Hari',
        ]);

        Http::assertNothingSent();
        Mail::assertNothingSent();
        $response->assertAccepted();
        $response->assertJson([
            'success' => true,
            'stats'   => [
                'total'            => 2,
                'email_pending'    => 2,
                'whatsapp_pending' => 2,
            ],
        ]);
    }
}
