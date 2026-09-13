<?php

use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    foreach (['s3', 'public', 'local'] as $disk) {
        Storage::fake($disk);
    }

    config([
        'rekrutmen.disk'                   => 's3',
        'rekrutmen.thumbnail_disk'         => 's3',
        'filesystems.default'              => 'local',
        'filament.default_filesystem_disk' => 'local',
    ]);
});

/** @param array<string, mixed> $attributes */
function storageApplication(array $attributes = []): JobApplication
{
    $posting = JobPosting::query()->create([
        'title'        => 'Software Engineer',
        'slug'         => 'software-engineer-'.str()->random(10),
        'description'  => 'Build systems',
        'requirements' => 'PHP',
        'location'     => 'Jakarta',
        'is_published' => true,
    ]);

    return JobApplication::query()->create(array_merge([
        'job_posting_id'  => $posting->id,
        'full_name'       => 'Storage Candidate',
        'email'           => str()->random(10).'@example.com',
        'whatsapp_number' => '081234567890',
        'active_phone'    => '081234567890',
        'status'          => JobApplicationStatus::IN_PROGRESS,
    ], $attributes))->fresh();
}

dataset('managed attachments', [
    'resume' => ['resume', 'rekrutmen/cv', 'pdf', 'CV'],
    'photo'  => ['photo', 'rekrutmen/photos', 'jpg', 'PHOTO'],
]);

it('pins new files to the configured module disk and keeps them there after a config switch', function (string $attachment, string $directory, string $extension): void {
    $path = $directory.'/upload.'.$extension;
    Storage::disk('s3')->put($path, 'original upload');
    Storage::disk('public')->put($path, 'unrelated public upload');
    $application = storageApplication([$attachment.'_path' => $path]);
    $storedPath = $application->{$attachment.'_path'};

    expect($application->{$attachment.'_disk'})->toBe('s3')
        ->and($application->resolveAttachmentDisk($attachment))->toBe('s3');
    Storage::disk('s3')->assertExists($storedPath);
    expect(Storage::disk('public')->get($path))->toBe('unrelated public upload');

    config(['rekrutmen.disk' => 'public']);
    $application->update(['full_name' => 'Updated Name']);
    expect($application->fresh()->{$attachment.'_disk'})->toBe('s3')
        ->and($application->resolveAttachmentDisk($attachment))->toBe('s3')
        ->and($application->{$attachment.'_path'})->toBe($storedPath);
    Storage::disk('s3')->assertExists($storedPath);
})->with('managed attachments');

it('uses each supported configured disk for new uploads', function (string $disk): void {
    config(['rekrutmen.disk' => $disk]);
    Storage::disk($disk)->put('rekrutmen/cv/upload.pdf', 'resume');
    $application = storageApplication(['resume_path' => 'rekrutmen/cv/upload.pdf']);

    expect(JobApplication::resumeDisk())->toBe($disk)
        ->and($application->resume_disk)->toBe($disk)
        ->and($application->resolveAttachmentDisk('resume'))->toBe($disk);
    Storage::disk($disk)->assertExists($application->resume_path);
})->with(['s3', 'public', 'local']);

it('does not resolve or delete a collision when the recorded disk file is missing', function (string $attachment, string $directory, string $extension): void {
    Storage::disk('s3')->put($directory.'/upload.'.$extension, 'original');
    $application = storageApplication([$attachment.'_path' => $directory.'/upload.'.$extension]);
    $path = $application->{$attachment.'_path'};
    Storage::disk('s3')->delete($path);
    Storage::disk('public')->put($path, 'different file');
    config(['rekrutmen.disk' => 'public']);

    expect($application->resolveAttachmentDisk($attachment))->toBeNull();
    $application->update([$attachment.'_path' => null]);
    expect($application->fresh()->{$attachment.'_disk'})->toBeNull()
        ->and(Storage::disk('public')->get($path))->toBe('different file');
})->with('managed attachments');

it('replaces on the new configured disk and preserves a conflicting target on that disk', function (string $attachment, string $directory, string $extension): void {
    Storage::disk('s3')->put($directory.'/old-upload.'.$extension, 'original');
    $application = storageApplication([$attachment.'_path' => $directory.'/old-upload.'.$extension]);
    $oldPath = $application->{$attachment.'_path'};
    Storage::disk('public')->put($oldPath, 'unrelated canonical file');
    Storage::disk('local')->put($oldPath, 'unrelated local file');
    Storage::disk('public')->put($directory.'/replacement.'.$extension, 'replacement');
    config(['rekrutmen.disk' => 'public']);

    $application->update([$attachment.'_path' => $directory.'/replacement.'.$extension]);
    $application->refresh();
    $newPath = $application->{$attachment.'_path'};
    expect($application->{$attachment.'_disk'})->toBe('public')
        ->and($newPath)->not->toBe($oldPath)
        ->and(Storage::disk('public')->get($newPath))->toBe('replacement')
        ->and(Storage::disk('public')->get($oldPath))->toBe('unrelated canonical file')
        ->and(Storage::disk('local')->get($oldPath))->toBe('unrelated local file');
    Storage::disk('s3')->assertMissing($oldPath);
})->with('managed attachments');

it('treats the same relative path on a different explicit disk as a replacement', function (string $attachment, string $directory, string $extension): void {
    Storage::disk('s3')->put($directory.'/old-upload.'.$extension, 'original');
    $application = storageApplication([$attachment.'_path' => $directory.'/old-upload.'.$extension]);
    $path = $application->{$attachment.'_path'};
    Storage::disk('public')->put($path, 'replacement');
    Storage::disk('local')->put($path, 'unrelated');
    config(['rekrutmen.disk' => 'local']);

    $application->update([$attachment.'_path' => $path, $attachment.'_disk' => 'public']);
    expect($application->fresh()->{$attachment.'_disk'})->toBe('public')
        ->and(Storage::disk('public')->get($path))->toBe('replacement')
        ->and(Storage::disk('local')->get($path))->toBe('unrelated');
    Storage::disk('s3')->assertMissing($path);
})->with('managed attachments');

it('honors an explicitly supplied unchanged disk when the uploaded path changes', function (): void {
    Storage::disk('s3')->put('rekrutmen/cv/original.pdf', 'original');
    $application = storageApplication(['resume_path' => 'rekrutmen/cv/original.pdf']);
    $canonical = $application->resume_path;
    Storage::disk('s3')->put('rekrutmen/cv/replacement.pdf', 'replacement');
    Storage::disk('public')->put('rekrutmen/cv/replacement.pdf', 'other file');
    config(['rekrutmen.disk' => 'public']);

    $application->update(['resume_path' => 'rekrutmen/cv/replacement.pdf', 'resume_disk' => 's3']);
    expect($application->fresh()->resume_disk)->toBe('s3')
        ->and($application->resume_path)->toBe($canonical)
        ->and(Storage::disk('s3')->get($canonical))->toBe('replacement')
        ->and(Storage::disk('public')->get('rekrutmen/cv/replacement.pdf'))->toBe('other file');
});

it('removes and force deletes only files on the pinned disk after configuration changes', function (string $action): void {
    Storage::disk('s3')->put('rekrutmen/cv/resume.pdf', 'resume');
    Storage::disk('s3')->put('rekrutmen/photos/photo.jpg', 'photo');
    $application = storageApplication(['resume_path' => 'rekrutmen/cv/resume.pdf', 'photo_path' => 'rekrutmen/photos/photo.jpg']);
    $paths = [$application->resume_path, $application->photo_path];
    foreach ($paths as $path) {
        Storage::disk('public')->put($path, 'public collision');
        Storage::disk('local')->put($path, 'local collision');
    }
    config(['rekrutmen.disk' => 'local']);

    if ($action === 'remove') {
        $application->update(['resume_path' => null, 'photo_path' => null]);
        expect($application->fresh()->resume_disk)->toBeNull()->and($application->photo_disk)->toBeNull();
    } else {
        $application->delete();
        Storage::disk('s3')->assertExists($paths);
        $application->forceDelete();
    }

    Storage::disk('s3')->assertMissing($paths);
    Storage::disk('public')->assertExists($paths);
    Storage::disk('local')->assertExists($paths);
})->with(['remove', 'force delete']);

it('pins a uniquely resolved legacy file without persisting unrelated unsaved changes', function (): void {
    Storage::disk('s3')->put('rekrutmen/cv/resume.pdf', 'resume');
    $application = storageApplication(['resume_path' => 'rekrutmen/cv/resume.pdf']);
    JobApplication::query()->whereKey($application->id)->update(['resume_disk' => null]);
    $application->refresh();
    $originalName = $application->full_name;
    $application->full_name = 'Unsaved name';
    config(['rekrutmen.disk' => 'public']);

    expect($application->resolveAttachmentDisk('resume'))->toBe('s3')
        ->and($application->fresh()->resume_disk)->toBe('s3')
        ->and($application->fresh()->full_name)->toBe($originalName)
        ->and($application->full_name)->toBe('Unsaved name');
    Storage::disk('public')->put($application->resume_path, 'new collision');
    expect($application->resolveAttachmentDisk('resume'))->toBe('s3');
});

it('does not rename, pin, or delete an ambiguous legacy attachment', function (string $action): void {
    $application = storageApplication();
    $path = 'rekrutmen/cv/legacy.pdf';
    JobApplication::query()->whereKey($application->id)->update(['resume_path' => $path, 'resume_disk' => null]);
    $application->refresh();
    Storage::disk('s3')->put($path, 's3 legacy');
    Storage::disk('public')->put($path, 'public legacy');
    config(['rekrutmen.disk' => 'public']);

    expect($application->resolveAttachmentDisk('resume'))->toBeNull();
    $application->update(['full_name' => 'Changed candidate']);
    expect($application->fresh()->resume_disk)->toBeNull()->and($application->resume_path)->toBe($path);

    if ($action === 'remove') {
        $application->update(['resume_path' => null]);
    } else {
        $application->forceDelete();
    }

    expect(Storage::disk('s3')->get($path))->toBe('s3 legacy')
        ->and(Storage::disk('public')->get($path))->toBe('public legacy');
})->with(['remove', 'force delete']);

it('keeps an unrelated canonical target intact during initial attachment assignment', function (): void {
    $application = storageApplication();
    $canonical = 'rekrutmen/cv/CV-'.$application->id.'-software-engineer.pdf';
    Storage::disk('s3')->put($canonical, 'unrelated canonical');
    Storage::disk('s3')->put('rekrutmen/cv/new-upload.pdf', 'new attachment');

    $application->update(['resume_path' => 'rekrutmen/cv/new-upload.pdf']);
    expect($application->resume_path)->not->toBe($canonical)
        ->and(Storage::disk('s3')->get($canonical))->toBe('unrelated canonical')
        ->and(Storage::disk('s3')->get($application->resume_path))->toBe('new attachment');
});

it('keeps recorded metadata when an unchanged attachment is submitted with a blank disk', function (): void {
    Storage::disk('s3')->put('rekrutmen/cv/resume.pdf', 'resume');
    $application = storageApplication(['resume_path' => 'rekrutmen/cv/resume.pdf']);
    Storage::disk('public')->put($application->resume_path, 'public collision');
    config(['rekrutmen.disk' => 'public']);

    $application->update(['resume_disk' => null]);
    expect($application->fresh()->resume_disk)->toBe('s3')
        ->and($application->resolveAttachmentDisk('resume'))->toBe('s3');
});
