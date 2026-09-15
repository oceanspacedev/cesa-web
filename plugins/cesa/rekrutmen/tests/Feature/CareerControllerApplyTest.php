<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Http\Controllers\Api\CareerController;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobApplicationSubmittedNotification;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class CareerControllerApplyTest extends RekrutmenTestCase
{
    public function test_job_application_submission_notification_uses_standard_notifications_queue(): void
    {
        $jobApplication = new JobApplication;
        $notification = new JobApplicationSubmittedNotification($jobApplication);

        $this->assertSame('notifications', config('rekrutmen.notifications.queue'));
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('notifications', $notification->queue);
    }

    public function test_public_apply_stores_the_new_candidate_profile_fields(): void
    {
        Notification::fake();

        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Hiring Pipeline',
        ]);

        $firstStage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer',
            'slug'                  => 'backend-developer',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $response = $this->post("/api/jobs/{$jobPosting->slug}/apply", [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ]);

        $response->assertCreated();

        $application = JobApplication::query()->where('email', 'budi@example.com')->first();

        $this->assertNotNull($application);
        $this->assertSame($jobPosting->id, $application->job_posting_id);
        $this->assertSame($firstStage->id, $application->current_stage_id);
        $this->assertSame('BUDI SANTOSO', $application->full_name);
        $this->assertSame('male', $application->gender?->value);
        $this->assertSame('single', $application->marital_status?->value);
        $this->assertSame('6281200000001', $application->whatsapp_number);
        $this->assertSame('6281200000002', $application->active_phone);
        $this->assertSame('BUNGA', $application->emergency_contact_name);
        $this->assertSame('ADIK KANDUNG', $application->emergency_contact_relation);
        $this->assertSame('6281200000003', $application->emergency_contact_phone);
        $this->assertNotNull($application->photo_path);
        $this->assertNotNull($application->resume_path);

        Storage::disk('local')->assertExists((string) $application->photo_path);
        Storage::disk('local')->assertExists((string) $application->resume_path);

        Notification::assertSentOnDemand(JobApplicationSubmittedNotification::class, function (
            JobApplicationSubmittedNotification $notification,
            array $channels,
            object $notifiable
        ) use ($application): bool {
            return in_array('mail', $channels, true)
                && ($notifiable->routes['mail'] ?? null) === $application->email;
        });
    }

    public function test_public_apply_requires_photo_and_resume(): void
    {
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Validation Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer',
            'slug'                  => 'backend-developer-validation',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $response = $this->postJson("/api/jobs/{$jobPosting->slug}/apply", [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'photo',
                'resume',
            ]);
    }

    public function test_public_apply_validates_source_before_persisting_application(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Source Validation Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Source',
            'slug'                  => 'backend-developer-source',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $response = $this->postJson("/api/jobs/{$jobPosting->slug}/apply", [
            'source'                     => str_repeat('a', 256),
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi-source@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000011',
            'active_phone'               => '081200000012',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000013',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
        $this->assertSame(0, JobApplication::query()->count());
    }

    public function test_public_apply_still_succeeds_when_submission_notification_fails(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');
        config()->set('rekrutmen.mail.job_application.mailer', 'missing-mailer');

        Log::spy();

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Notification Failure Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Notify',
            'slug'                  => 'backend-developer-notify',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $this->post("/api/jobs/{$jobPosting->slug}/apply", [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, JobApplication::query()->count());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_public_apply_rejects_duplicate_email_for_the_same_job_posting(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Duplicate Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Duplicate',
            'slug'                  => 'backend-developer-duplicate',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $payload = [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ];

        $this->post("/api/jobs/{$jobPosting->slug}/apply", $payload)->assertCreated();

        $duplicateResponse = $this->postJson("/api/jobs/{$jobPosting->slug}/apply", [
            ...collect($payload)->except(['photo', 'resume'])->all(),
            'email'  => '  BUDI@EXAMPLE.COM  ',
            'photo'  => UploadedFile::fake()->image('photo.jpg'),
            'resume' => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ]);

        $duplicateResponse->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
            ]);

        $this->assertSame(1, JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('email', 'budi@example.com')
            ->count());
    }

    public function test_public_apply_rejects_duplicate_whatsapp_for_the_same_job_posting(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Duplicate Whatsapp Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Duplicate Whatsapp',
            'slug'                  => 'backend-developer-duplicate-whatsapp',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $payload = [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ];

        $this->post("/api/jobs/{$jobPosting->slug}/apply", $payload)->assertCreated();

        $duplicateResponse = $this->postJson("/api/jobs/{$jobPosting->slug}/apply", [
            ...collect($payload)->except(['photo', 'resume'])->all(),
            'email'           => 'budi2@example.com',
            'whatsapp_number' => '+62 812 0000 0001',
            'photo'           => UploadedFile::fake()->image('photo.jpg'),
            'resume'          => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ]);

        $duplicateResponse->assertStatus(422)
            ->assertJsonValidationErrors([
                'whatsapp_number',
            ]);
    }

    public function test_public_apply_allows_reapply_after_a_soft_deleted_application(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Reapply Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Reapply',
            'slug'                  => 'backend-developer-reapply',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $payload = [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ];

        $this->post("/api/jobs/{$jobPosting->slug}/apply", $payload)->assertCreated();

        $application = JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('email', 'budi@example.com')
            ->firstOrFail();

        $application->delete();

        $this->post("/api/jobs/{$jobPosting->slug}/apply", [
            ...$payload,
            'photo'  => UploadedFile::fake()->image('photo.jpg'),
            'resume' => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->count());
        $this->assertSame(2, JobApplication::withTrashed()
            ->where('job_posting_id', $jobPosting->id)
            ->count());
    }

    public function test_restored_application_rehydrates_active_email_and_blocks_duplicate_reapply(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Restore Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Restore',
            'slug'                  => 'backend-developer-restore',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $payload = [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ];

        $this->post("/api/jobs/{$jobPosting->slug}/apply", $payload)->assertCreated();

        $application = JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('email', 'budi@example.com')
            ->firstOrFail();

        $application->delete();
        $this->assertNull(DB::table('rekrutmen_job_applications')->where('id', $application->id)->value('active_email'));

        $application->restore();
        $this->assertSame(
            'budi@example.com',
            DB::table('rekrutmen_job_applications')->where('id', $application->id)->value('active_email')
        );

        $this->postJson("/api/jobs/{$jobPosting->slug}/apply", [
            ...collect($payload)->except(['photo', 'resume'])->all(),
            'photo'  => UploadedFile::fake()->image('photo.jpg'),
            'resume' => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
            ]);
    }

    public function test_public_apply_rejects_job_posting_without_an_initial_stage(): void
    {
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'No Stage Pipeline',
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer No Stage',
            'slug'                  => 'backend-developer-no-stage',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $response = $this->postJson("/api/jobs/{$jobPosting->slug}/apply", [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('rekrutmen_job_applications', 0);
    }

    public function test_public_apply_accepts_job_posting_through_its_closing_date(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Closing Today Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Closing Today',
            'slug'                  => 'backend-developer-closing-today',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
            'closing_date'          => today()->toDateString(),
        ]);

        $this->post("/api/jobs/{$jobPosting->slug}/apply", [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'closing-today@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'photo'                      => UploadedFile::fake()->image('photo.jpg'),
            'resume'                     => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ])->assertCreated();

        $this->assertDatabaseHas('rekrutmen_job_applications', [
            'job_posting_id' => $jobPosting->id,
            'email'          => 'closing-today@example.com',
        ]);
    }

    public function test_job_specific_form_configuration_controls_api_shape_and_validation(): void
    {
        config()->set('rekrutmen.application_form.by_slug.backend-developer-configurable', [
            [
                'name'     => 'whatsapp_number',
                'label'    => 'custom.whatsapp',
                'type'     => 'text',
                'required' => false,
            ],
            [
                'name'     => 'gender',
                'required' => false,
            ],
            [
                'name'     => 'photo',
                'required' => false,
            ],
            [
                'name'     => 'resume',
                'required' => false,
            ],
            [
                'name'     => 'portfolio_url',
                'label'    => 'custom.portfolio',
                'type'     => 'text',
                'required' => false,
            ],
        ]);

        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('rekrutmen.disk', 'local');
        config()->set('filament.default_filesystem_disk', 'local');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Configurable Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer',
            'slug'                  => 'backend-developer-configurable',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $detailPayload = app(CareerController::class)->show($jobPosting->slug)->getData(true);
        $formFields = collect($detailPayload['data']['application_form']);

        $this->assertFalse($formFields->contains(fn (array $field): bool => ($field['name'] ?? null) === 'portfolio_url'));
        $this->assertSame(
            false,
            $formFields->firstWhere('name', 'whatsapp_number')['required'] ?? true
        );
        $this->assertSame(
            false,
            $formFields->firstWhere('name', 'gender')['required'] ?? true
        );
        $this->assertSame(
            __('rekrutmen::config/application-form.fields.gender'),
            $formFields->firstWhere('name', 'gender')['label'] ?? null
        );
        $this->assertCount(
            2,
            $formFields->firstWhere('name', 'gender')['options'] ?? []
        );

        $response = $this->post("/api/jobs/{$jobPosting->slug}/apply", [
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'configurable@example.com',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
        ]);

        $response->assertCreated();

        $application = JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('email', 'configurable@example.com')
            ->first();

        $this->assertNotNull($application);
        $this->assertNull($application->whatsapp_number);
        $this->assertNull($application->gender);
        $this->assertNull($application->photo_path);
        $this->assertNull($application->resume_path);
    }

    public function test_job_application_submitted_notification_contains_summary_details(): void
    {
        config()->set('rekrutmen.mail.job_application.mailer', 'rekrutmen_job_application');
        config()->set('rekrutmen.mail.job_application.from.address', 'noreply@oceanspace.co.id');
        config()->set('rekrutmen.mail.job_application.from.name', 'OceanSpace Recruitment');
        config()->set('rekrutmen.mail.job_application.reply_to.address', 'recruitment@oceanspace.co.id');
        config()->set('rekrutmen.mail.job_application.reply_to.name', 'OceanSpace Recruitment');

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Mail Pipeline',
        ]);

        $stage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening CV',
            'order_column'          => 1,
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer Mail',
            'slug'                  => 'backend-developer-mail',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $application = JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $stage->id,
            'full_name'                  => 'Budi Santoso',
            'email'                      => 'budi@example.com',
            'gender'                     => 'male',
            'birth_date'                 => '1995-01-10',
            'marital_status'             => 'single',
            'address_ktp'                => 'Jl. KTP No. 1, Jakarta',
            'address_domicile'           => 'Jl. Domisili No. 2, Bekasi',
            'whatsapp_number'            => '081200000001',
            'active_phone'               => '081200000002',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081200000003',
            'status'                     => 'in_progress',
        ])->fresh(['jobPosting', 'currentStage']);

        $mail = (new JobApplicationSubmittedNotification($application))->toMail(new \stdClass);

        $this->assertSame(
            __('rekrutmen::mail/job-application-submitted.subject', ['position' => 'Backend Developer Mail']),
            $mail->subject
        );
        $this->assertSame('rekrutmen::mail.job-application-submitted', $mail->view);
        $this->assertSame('rekrutmen_job_application', $mail->mailer);
        $this->assertNull($mail->viewData['progressUrl']);
        $this->assertSame(
            __('rekrutmen::mail/job-application-submitted.footer_note'),
            $mail->viewData['footerNote']
        );
        $this->assertSame(['noreply@oceanspace.co.id', 'OceanSpace Recruitment'], $mail->from);
        $this->assertSame([['recruitment@oceanspace.co.id', 'OceanSpace Recruitment']], $mail->replyTo);
        $this->assertSame((string) $application->id, $mail->viewData['summary'][0]['value']);
        $this->assertSame('Backend Developer Mail', $mail->viewData['summary'][2]['value']);
    }
}
