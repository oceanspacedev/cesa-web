<?php

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Services\AiScreeningService;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    foreach (['s3', 'public', 'local'] as $disk) {
        Storage::fake($disk);
    }
    config(['rekrutmen.disk' => 's3', 'rekrutmen.thumbnail_disk' => 's3']);
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
});

function recruitmentStorageCandidate(array $attributes = []): JobApplication
{
    $posting = JobPosting::query()->create([
        'title' => 'Storage Engineer', 'slug' => 'storage-'.str()->uuid(), 'is_published' => false,
    ]);

    return JobApplication::query()->create(array_merge([
        'job_posting_id' => $posting->id,
        'full_name'      => 'Storage Candidate',
        'email'          => fake()->unique()->safeEmail(),
        'status'         => 'in_progress',
    ], $attributes));
}

function recruitmentStorageOperator(): User
{
    $user = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    foreach (['view_any_rekrutmen_job::application', 'view_rekrutmen_job::application', 'update_rekrutmen_job::application'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    return $user;
}

it('stores files on the recruitment disk with its configured visibility', function (string $disk, string $visibility): void {
    config(['rekrutmen.disk' => $disk, 'filesystems.default' => 'local', 'filament.default_filesystem_disk' => 'local']);
    $path = app(RekrutmenStorage::class)->storeUploadedFile(UploadedFile::fake()->create('cv.pdf', 1), JobApplication::RESUME_DIRECTORY);

    Storage::disk($disk)->assertExists($path);
    expect(Storage::disk($disk)->getVisibility($path))->toBe($visibility)
        ->and(config('filesystems.default'))->toBe('local');
    foreach (array_diff(['s3', 'public', 'local'], [$disk]) as $otherDisk) {
        Storage::disk($otherDisk)->assertMissing($path);
    }
})->with([['s3', 'private'], ['public', 'public'], ['local', 'private']]);

it('rejects an unconfigured recruitment disk without falling back to another module', function (): void {
    config(['rekrutmen.disk' => 'unavailable', 'filesystems.default' => 'public']);
    expect(fn (): string => app(RekrutmenStorage::class)->disk())->toThrow(InvalidArgumentException::class);
});

it('never resolves a different disk when the recorded copy is missing', function (): void {
    Storage::disk('public')->put('rekrutmen/cv/shared.pdf', 'public-file');
    expect(app(RekrutmenStorage::class)->resolveDisk('rekrutmen/cv/shared.pdf', 's3'))->toBeNull();
});

it('leaves ambiguous legacy paths unresolved', function (): void {
    Storage::disk('s3')->put('rekrutmen/cv/shared.pdf', 's3-file');
    Storage::disk('public')->put('rekrutmen/cv/shared.pdf', 'public-file');
    expect(app(RekrutmenStorage::class)->resolveDisk('rekrutmen/cv/shared.pdf'))->toBeNull();
});

it('rejects paths that can escape the selected disk', function (string $path): void {
    expect(app(RekrutmenStorage::class)->resolveDisk($path, 'local'))->toBeNull();
})->with(['../secret', 'rekrutmen/../../secret', 'rekrutmen\\..\\secret', "rekrutmen/\0secret"]);

it('uploads an admin CV to the configured disk and records its origin', function (string $disk): void {
    config(['rekrutmen.disk' => $disk]);
    $this->actingAs(recruitmentStorageOperator());
    $application = recruitmentStorageCandidate();

    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/upload-cv', [
        'cv' => UploadedFile::fake()->create('resume.pdf', 10, 'application/pdf'),
    ])->assertSuccessful();

    $application->refresh();
    expect($application->resume_disk)->toBe($disk);
    Storage::disk($disk)->assertExists($application->resume_path);
    foreach (array_diff(['s3', 'public', 'local'], [$disk]) as $otherDisk) {
        Storage::disk($otherDisk)->assertMissing($application->resume_path);
    }
})->with(['s3', 'public', 'local']);

it('reads CV and photo from their recorded disks after the default changes', function (): void {
    $this->actingAs(recruitmentStorageOperator());
    $application = recruitmentStorageCandidate();
    $cvPath = 'rekrutmen/cv/CV-'.$application->id.'-stored.pdf';
    $photoPath = 'rekrutmen/photos/PHOTO-'.$application->id.'-stored.jpg';
    Storage::disk('s3')->put($cvPath, 'original-cv');
    Storage::disk('s3')->put($photoPath, 'original-photo');
    $application->update(['resume_path' => $cvPath, 'resume_disk' => 's3', 'photo_path' => $photoPath, 'photo_disk' => 's3']);
    Storage::disk('public')->put($cvPath, 'unrelated-cv');
    Storage::disk('public')->put($photoPath, 'unrelated-photo');
    config(['rekrutmen.disk' => 'public']);

    expect($this->get('/rekrutmen/api/applications/'.$application->id.'/cv')->assertOk()->streamedContent())->toBe('original-cv')
        ->and($this->get('/rekrutmen/api/applications/'.$application->id.'/photo')->assertOk()->streamedContent())->toBe('original-photo')
        ->and($this->get(URL::temporarySignedRoute('rekrutmen.job-applications.attachments.download', now()->addMinutes(5), [$application, 'resume']))->assertOk()->streamedContent())->toBe('original-cv');
});

it('returns not found rather than exposing a colliding file when the original disappears', function (): void {
    $this->actingAs(recruitmentStorageOperator());
    $application = recruitmentStorageCandidate();
    $path = 'rekrutmen/cv/CV-'.$application->id.'-missing.pdf';
    $application->update(['resume_path' => $path, 'resume_disk' => 's3']);
    Storage::disk('public')->put($path, 'not-this-candidate');

    $this->get('/rekrutmen/api/applications/'.$application->id.'/cv')->assertNotFound();
    $this->get(URL::temporarySignedRoute('rekrutmen.job-applications.attachments.download', now()->addMinutes(5), [$application, 'resume']))->assertNotFound();
});

it('authorizes file reads and uploads using the candidate policy', function (): void {
    $application = recruitmentStorageCandidate();
    $this->actingAs(User::factory()->create(['is_active' => true]));
    $this->get('/rekrutmen/api/applications/'.$application->id.'/cv')->assertForbidden();
    $this->get('/rekrutmen/api/applications/'.$application->id.'/photo')->assertForbidden();
    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/upload-cv', [
        'cv' => UploadedFile::fake()->create('resume.pdf', 1, 'application/pdf'),
    ])->assertForbidden();
    expect(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('pins a unique legacy CV during a read without copying it to the new default', function (): void {
    $this->actingAs(recruitmentStorageOperator());
    $application = recruitmentStorageCandidate();
    $path = 'rekrutmen/cv/CV-'.$application->id.'-legacy.pdf';
    Storage::disk('public')->put($path, 'legacy');
    JobApplication::query()->whereKey($application->id)->update(['resume_path' => $path, 'resume_disk' => null]);

    expect($this->get('/rekrutmen/api/applications/'.$application->id.'/cv')->assertOk()->streamedContent())->toBe('legacy')
        ->and($application->fresh()->resume_disk)->toBe('public');
    Storage::disk('s3')->assertMissing($path);
});

it('syncs only an unambiguous candidate ID and respects dry run and recorded disks', function (): void {
    $legacy = recruitmentStorageCandidate();
    $ambiguous = recruitmentStorageCandidate();
    $recorded = recruitmentStorageCandidate(['resume_path' => 'rekrutmen/cv/missing.pdf', 'resume_disk' => 's3']);
    $legacyPath = 'rekrutmen/cv/CV-'.$legacy->id.'-legacy.pdf';
    Storage::disk('public')->put($legacyPath, 'legacy');
    foreach (['s3', 'public'] as $disk) {
        Storage::disk($disk)->put('rekrutmen/cv/CV-'.$ambiguous->id.'-ambiguous.pdf', $disk);
    }
    Storage::disk('public')->put('rekrutmen/cv/CV-'.$recorded->id.'-unrelated.pdf', 'unrelated');

    $this->artisan('rekrutmen:sync-cv', ['--dry-run' => true])->assertSuccessful();
    expect($legacy->fresh()->resume_path)->toBeNull();
    $this->artisan('rekrutmen:sync-cv')->assertSuccessful();
    expect($legacy->fresh()->resume_path)->toBe($legacyPath)
        ->and($legacy->fresh()->resume_disk)->toBe('public')
        ->and($ambiguous->fresh()->resume_path)->toBeNull()
        ->and($recorded->fresh()->resume_disk)->toBe('s3')
        ->and($recorded->fresh()->resume_path)->toBe('rekrutmen/cv/missing.pdf');
});

it('reads fresh CV contents for screening without mixing disks or cached replacements', function (): void {
    $application = recruitmentStorageCandidate();
    $path = 'rekrutmen/cv/CV-'.$application->id.'-screening.pdf';
    Storage::disk('s3')->put($path, 'original');
    $application->update(['resume_path' => $path, 'resume_disk' => 's3']);
    Storage::disk('public')->put($path, 'collision');
    config(['rekrutmen.disk' => 'public']);
    $method = new ReflectionMethod(AiScreeningService::class, 'readCvContents');
    $screening = app(AiScreeningService::class);

    expect($method->invoke($screening, $application))->toBe('original');
    Storage::disk('s3')->put($path, 'replacement');
    expect($method->invoke($screening, $application))->toBe('replacement');
});

it('keeps SPA thumbnails on their source disk and stores replacements on the selected disk', function (string $firstDisk, string $nextDisk): void {
    $this->actingAs(recruitmentStorageOperator());
    config(['rekrutmen.thumbnail_disk' => $firstDisk]);
    $response = $this->postJson('/rekrutmen/api/job-postings', [
        'title'     => 'Thumbnail Storage',
        'thumbnail' => UploadedFile::fake()->image('first.jpg'),
    ])->assertCreated();
    $posting = JobPosting::query()->findOrFail($response->json('posting.id'));
    $firstPath = $posting->thumbnail_path;
    expect($posting->thumbnail_disk)->toBe($firstDisk);
    Storage::disk($firstDisk)->assertExists($firstPath);
    Storage::disk($nextDisk)->put($firstPath, 'unrelated-thumbnail');
    config(['rekrutmen.thumbnail_disk' => $nextDisk]);

    $this->putJson('/rekrutmen/api/job-postings/'.$posting->id, ['title' => 'Unrelated title edit'])->assertOk();
    expect($posting->fresh()->thumbnail_disk)->toBe($firstDisk);
    $this->postJson('/rekrutmen/api/job-postings/'.$posting->id, [
        'title'     => 'New thumbnail',
        'thumbnail' => UploadedFile::fake()->image('replacement.jpg'),
    ])->assertOk();
    $posting->refresh();
    expect($posting->thumbnail_disk)->toBe($nextDisk);
    Storage::disk($nextDisk)->assertExists($posting->thumbnail_path);
    Storage::disk($firstDisk)->assertMissing($firstPath);
    expect(Storage::disk($nextDisk)->get($firstPath))->toBe('unrelated-thumbnail');
})->with([['s3', 'public'], ['public', 's3']]);

it('lets individually scoped operators sync their own candidate files only', function (): void {
    $user = recruitmentStorageOperator();
    $user->update(['resource_permission' => PermissionType::INDIVIDUAL]);
    $this->actingAs($user);
    $owned = recruitmentStorageCandidate();
    $other = recruitmentStorageCandidate();
    $other->forceFill(['creator_id' => User::factory()->create()->id])->save();
    foreach ([$owned, $other] as $application) {
        Storage::disk('public')->put('rekrutmen/cv/CV-'.$application->id.'-legacy.pdf', 'legacy');
    }

    $this->postJson('/rekrutmen/api/applications/sync-cvs')->assertOk()->assertJsonPath('matched', 1);
    expect($owned->fresh()->resume_disk)->toBe('public')->and($other->fresh()->resume_disk)->toBeNull();
});
