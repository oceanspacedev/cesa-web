<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobApplicationSubmittedNotification;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\MailSetting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\WhatsAppSetting;
use Cesa\Rekrutmen\Services\CandidateWhatsAppNotifier;
use Cesa\Rekrutmen\Services\RekrutmenMailer;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Models\User;

class CommunicationGatewayTest extends RekrutmenTestCase
{
    public function test_mail_settings_can_be_saved_and_override_rekrutmen_mailer_only(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $response = $this->putJson('/rekrutmen/api/settings/mail', [
            'enabled'          => true,
            'transport'        => 'smtp',
            'host'             => 'smtp.rekrutmen.test',
            'port'             => 587,
            'encryption'       => 'tls',
            'username'         => 'rekrutmen@example.com',
            'password'         => 'secret-pass',
            'from_address'     => 'rekrutmen@example.com',
            'from_name'        => 'Tim Rekrutmen CESA',
            'reply_to_address' => 'hr@example.com',
            'reply_to_name'    => 'HR Rekrutmen',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data'    => [
                'enabled'      => true,
                'host'         => 'smtp.rekrutmen.test',
                'from_address' => 'rekrutmen@example.com',
                'has_password' => true,
            ],
        ]);

        $this->assertNull($response->json('data.password'));
        $this->assertDatabaseCount('rekrutmen_mail_settings', 1);

        $mailer = app(RekrutmenMailer::class);
        $this->assertSame(RekrutmenMailer::MAILER, $mailer->mailerName());
        $this->assertSame('rekrutmen@example.com', $mailer->from()['address']);
        $this->assertSame('smtp', config('mail.mailers.smtp.transport'));
        $this->assertNotSame('smtp.rekrutmen.test', config('mail.mailers.smtp.host'));
    }

    public function test_mail_test_endpoint_sends_using_rekrutmen_mailer(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        MailSetting::query()->create([
            'enabled'      => true,
            'transport'    => 'log',
            'from_address' => 'rekrutmen@example.com',
            'from_name'    => 'Tim Rekrutmen',
        ]);

        $response = $this->postJson('/rekrutmen/api/settings/mail/test', [
            'recipient'    => 'tester@example.com',
            'enabled'      => true,
            'transport'    => 'log',
            'from_address' => 'rekrutmen@example.com',
            'from_name'    => 'Tim Rekrutmen',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_job_application_notification_uses_ui_mail_settings_when_enabled(): void
    {
        MailSetting::query()->create([
            'enabled'          => true,
            'transport'        => 'log',
            'from_address'     => 'ui-rekrutmen@example.com',
            'from_name'        => 'UI Rekrutmen',
            'reply_to_address' => 'reply-ui@example.com',
            'reply_to_name'    => 'Reply UI',
        ]);

        $pipeline = RekrutmenPipeline::query()->create(['name' => 'Mail UI Pipeline']);
        $stage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);
        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Staff UI Mail',
            'slug'                  => 'staff-ui-mail-'.uniqid(),
            'is_published'          => true,
        ]);
        $application = JobApplication::query()->create([
            'job_posting_id'   => $jobPosting->id,
            'current_stage_id' => $stage->id,
            'full_name'        => 'Andi Wijaya',
            'email'            => 'andi@example.com',
            'whatsapp_number'  => '081200000099',
            'status'           => 'in_progress',
        ])->fresh(['jobPosting']);

        $mail = (new JobApplicationSubmittedNotification($application))->toMail(new \stdClass);

        $this->assertSame(RekrutmenMailer::MAILER, $mail->mailer);
        $this->assertSame(['ui-rekrutmen@example.com', 'UI Rekrutmen'], $mail->from);
        $this->assertSame([['reply-ui@example.com', 'Reply UI']], $mail->replyTo);
    }

    public function test_whatsapp_connects_by_qr_without_api_key_and_can_select_sender(): void
    {
        $this->fakeRekrutmenWhatsAppEngine([
            'status'       => 'qr',
            'qr'           => 'data:image/png;base64,qqq',
            'pairing_code' => '1234-5678',
            'phone'        => null,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web'));
        $this->actingAs($user);

        $this->putJson('/rekrutmen/api/settings/whatsapp', [
            'enabled' => true,
        ])->assertOk()->assertJsonMissing(['has_api_key']);

        $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', [
            'name' => 'HR Recruitment',
            'mode' => 'pairing',
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone_number']);

        $connect = $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', [
            'name'         => 'HR Recruitment',
            'phone_number' => '081211112222',
            'mode'         => 'pairing',
        ]);

        $connect->assertCreated();
        $this->assertSame('qr', $connect->json('data.status'));
        $this->assertSame('data:image/png;base64,qqq', $connect->json('data.qr'));
        $this->assertNull($connect->json('data.pairing_code'));
        $this->assertArrayNotHasKey('api_key', $connect->json('data'));

        $accountId = $connect->json('data.id');
        $this->assertNotNull($accountId);

        $second = $this->makeConnectedWhatsAppAccount([
            'name'         => 'Talent Acquisition',
            'phone_number' => '6281233334444',
            'is_default'   => false,
        ]);

        $this->fakeRekrutmenWhatsAppEngine(['status' => 'connected', 'phone' => '6281211112222']);

        $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/'.$accountId.'/test', [
            'recipient' => '081255556666',
        ])->assertOk();

        Http::assertSent(function (HttpRequest $request) use ($accountId): bool {
            $body = $request->data();

            return str_contains($request->url(), '/sessions/rekrutmen-'.$accountId.'/send')
                && ($body['phone'] ?? null) === '6281255556666';
        });

        $pipeline = RekrutmenPipeline::query()->create(['name' => 'WA Pipeline']);
        $stage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);
        $posting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Admin',
            'slug'                  => 'admin-wa-'.uniqid(),
            'is_published'          => true,
        ]);
        $application = JobApplication::query()->create([
            'job_posting_id'   => $posting->id,
            'current_stage_id' => $stage->id,
            'full_name'        => 'Sari Putri',
            'email'            => 'sari@example.com',
            'whatsapp_number'  => '081277778888',
            'status'           => 'in_progress',
        ]);

        $result = app(CandidateWhatsAppNotifier::class)->send($application, [
            'body_message'        => 'Halo {nama_pelamar}',
            'whatsapp_account_id' => $second->id,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('rekrutmen-'.$second->id, $result['session_id']);

        Http::assertSent(function (HttpRequest $request) use ($second): bool {
            $body = $request->data();

            return str_contains($request->url(), '/sessions/rekrutmen-'.$second->id.'/send')
                && ($body['phone'] ?? null) === '6281277778888';
        });
    }

    public function test_whatsapp_test_send_requires_recipient_and_uses_connected_number(): void
    {
        $this->fakeRekrutmenWhatsAppEngine();
        $account = $this->makeConnectedWhatsAppAccount([
            'phone_number' => '6281111111111',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web'));
        $this->actingAs($user);

        $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/'.$account->id.'/test')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient']);

        $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/'.$account->id.'/test', [
            'recipient' => '081299990000',
        ])->assertOk()->assertJson([
            'success' => true,
        ]);

        Http::assertSent(function (HttpRequest $request) use ($account): bool {
            $body = $request->data();

            return str_contains($request->url(), '/sessions/rekrutmen-'.$account->id.'/send')
                && ($body['phone'] ?? null) === '6281299990000'
                && str_contains((string) ($body['text'] ?? ''), 'tes koneksi WhatsApp');
        });
    }

    public function test_whatsapp_settings_are_isolated_from_unauthenticated_access(): void
    {
        $this->getJson('/rekrutmen/api/settings/whatsapp')->assertUnauthorized();
        $this->getJson('/rekrutmen/api/settings/mail')->assertUnauthorized();
    }

    public function test_saving_mail_settings_does_not_expose_password(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        MailSetting::query()->create([
            'enabled'      => true,
            'transport'    => 'smtp',
            'host'         => 'smtp.keep.test',
            'port'         => 587,
            'password'     => 'original-secret',
            'from_address' => 'keep@example.com',
        ]);

        $this->putJson('/rekrutmen/api/settings/mail', [
            'enabled'      => true,
            'transport'    => 'smtp',
            'host'         => 'smtp.keep.test',
            'port'         => 465,
            'encryption'   => 'ssl',
            'from_address' => 'keep@example.com',
        ])->assertOk();

        $this->assertSame('original-secret', MailSetting::query()->first()?->password);
        $this->assertSame(465, MailSetting::query()->first()?->port);
        $this->assertTrue(WhatsAppSetting::query()->doesntExist());
    }
}
