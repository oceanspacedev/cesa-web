<?php

namespace Cesa\Rekrutmen\Tests\Feature\Models;

use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Storage;

class JobApplicationAttachmentLifecycleTest extends RekrutmenTestCase
{
    public function test_resume_and_photo_are_renamed_replaced_and_removed(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('filament.default_filesystem_disk', 'local');
        config()->set('rekrutmen.disk', 'local');

        $jobPosting = $this->createJobPosting();

        Storage::disk('local')->put('rekrutmen/photos/tmp-photo-a.jpg', 'photo-a');
        Storage::disk('local')->put('rekrutmen/cv/tmp-resume-a.pdf', 'resume-a');

        $application = JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'full_name'                  => 'Alice Candidate',
            'email'                      => 'alice@example.com',
            'gender'                     => JobApplicationGender::Female,
            'birth_date'                 => '1999-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single,
            'address_ktp'                => 'Alamat KTP A',
            'address_domicile'           => 'Alamat Domisili A',
            'whatsapp_number'            => '081234567890',
            'active_phone'               => '081234567890',
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Adik Kandung',
            'emergency_contact_phone'    => '081111111111',
            'photo_path'                 => 'rekrutmen/photos/tmp-photo-a.jpg',
            'resume_path'                => 'rekrutmen/cv/tmp-resume-a.pdf',
            'status'                     => JobApplicationStatus::IN_PROGRESS,
        ])->fresh();

        $this->assertNotNull($application);
        $this->assertMatchesRegularExpression(
            '#^rekrutmen/cv/CV-'.$application->id.'-software-engineer\\.pdf$#',
            (string) $application->resume_path
        );

        $firstStoredPath = (string) $application->resume_path;
        $firstStoredPhotoPath = (string) $application->photo_path;

        Storage::disk('local')->assertMissing('rekrutmen/photos/tmp-photo-a.jpg');
        Storage::disk('local')->assertMissing('rekrutmen/cv/tmp-resume-a.pdf');
        Storage::disk('local')->assertExists($firstStoredPhotoPath);
        Storage::disk('local')->assertExists($firstStoredPath);

        Storage::disk('local')->put('rekrutmen/photos/tmp-photo-b.jpg', 'photo-b');
        Storage::disk('local')->put('rekrutmen/cv/tmp-resume-b.pdf', 'resume-b');

        $application->update([
            'photo_path'  => 'rekrutmen/photos/tmp-photo-b.jpg',
            'resume_path' => 'rekrutmen/cv/tmp-resume-b.pdf',
        ]);

        $application->refresh();

        $this->assertSame($firstStoredPhotoPath, $application->photo_path);
        $this->assertSame($firstStoredPath, $application->resume_path);
        Storage::disk('local')->assertMissing('rekrutmen/photos/tmp-photo-b.jpg');
        Storage::disk('local')->assertMissing('rekrutmen/cv/tmp-resume-b.pdf');
        Storage::disk('local')->assertExists((string) $application->photo_path);
        Storage::disk('local')->assertExists((string) $application->resume_path);

        $secondStoredPhotoPath = (string) $application->photo_path;
        $secondStoredPath = (string) $application->resume_path;

        $application->update([
            'photo_path'  => null,
            'resume_path' => null,
        ]);

        $application->refresh();

        $this->assertNull($application->photo_path);
        $this->assertNull($application->resume_path);
        Storage::disk('local')->assertMissing($secondStoredPhotoPath);
        Storage::disk('local')->assertMissing($secondStoredPath);
    }

    public function test_soft_delete_keeps_resume_until_force_delete(): void
    {
        Storage::fake('local');

        config()->set('filesystems.default', 'local');
        config()->set('filament.default_filesystem_disk', 'local');
        config()->set('rekrutmen.disk', 'local');

        $jobPosting = $this->createJobPosting();

        Storage::disk('local')->put('rekrutmen/photos/tmp-photo.jpg', 'photo');
        Storage::disk('local')->put('rekrutmen/cv/tmp-resume.pdf', 'resume');

        $application = JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'full_name'                  => 'Bob Candidate',
            'email'                      => 'bob@example.com',
            'gender'                     => JobApplicationGender::Male,
            'birth_date'                 => '1997-02-02',
            'marital_status'             => JobApplicationMaritalStatus::Married,
            'address_ktp'                => 'Alamat KTP B',
            'address_domicile'           => 'Alamat Domisili B',
            'whatsapp_number'            => '081234567891',
            'active_phone'               => '081234567891',
            'emergency_contact_name'     => 'Andi',
            'emergency_contact_relation' => 'Kakak Kandung',
            'emergency_contact_phone'    => '082222222222',
            'photo_path'                 => 'rekrutmen/photos/tmp-photo.jpg',
            'resume_path'                => 'rekrutmen/cv/tmp-resume.pdf',
            'status'                     => JobApplicationStatus::IN_PROGRESS,
        ])->fresh();

        $storedPhotoPath = (string) $application->photo_path;
        $storedPath = (string) $application->resume_path;

        $application->delete();

        Storage::disk('local')->assertExists($storedPhotoPath);
        Storage::disk('local')->assertExists($storedPath);

        $application->forceDelete();

        Storage::disk('local')->assertMissing($storedPhotoPath);
        Storage::disk('local')->assertMissing($storedPath);
    }

    private function createJobPosting(): JobPosting
    {
        return JobPosting::query()->create([
            'title'        => 'Software Engineer',
            'slug'         => 'software-engineer-'.str()->lower(str()->random(6)),
            'description'  => 'Build systems',
            'requirements' => 'PHP, Laravel',
            'location'     => 'Jakarta',
            'is_published' => true,
        ]);
    }
}
