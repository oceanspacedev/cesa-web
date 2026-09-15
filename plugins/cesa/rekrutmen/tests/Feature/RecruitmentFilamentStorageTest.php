<?php

use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Filament\Resources\JobApplicationResource\Pages\EditJobApplication;
use Cesa\Rekrutmen\Filament\Resources\JobPostingResource\Pages\EditJobPosting;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    config([
        'app.key'                             => 'base64:'.base64_encode(str_repeat('x', 32)),
        'app.debug'                           => true,
        'rekrutmen.disk'                      => 'local',
        'rekrutmen.thumbnail_disk'            => 'local',
        'livewire.temporary_file_upload.disk' => 'local',
        'filesystems.disks.private_media'     => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/private_media')],
    ]);

    foreach (['local', 'public', 's3', 'private_media'] as $disk) {
        Storage::fake($disk);
    }

    Filament::setCurrentPanel('admin');
    $user = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $this->actingAs($user);

    foreach (['posting', 'application'] as $resource) {
        foreach (['view_any', 'view', 'update', 'create'] as $action) {
            $permission = Permission::findOrCreate($action.'_rekrutmen_job::'.$resource, 'web');
            $user->givePermissionTo($permission);
        }
    }

    foreach (['job-postings', 'job-applications'] as $resource) {
        foreach (['index', 'edit', 'view', 'create', 'board'] as $page) {
            $routeName = 'filament.admin.resources.'.$resource.'.'.$page;
            if (! Route::has($routeName)) {
                Route::get('/testing/'.$resource.'/'.$page, fn (): string => 'ok')->name($routeName);
            }
        }
    }

    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Storage tests']);
    RekrutmenStage::query()->create(['rekrutmen_pipeline_id' => $pipeline->id, 'name' => 'Screening', 'order_column' => 1]);
    $this->posting = JobPosting::query()->create([
        'title'                 => 'Storage test vacancy',
        'slug'                  => 'storage-test-vacancy',
        'description'           => 'Vacancy description',
        'requirements'          => 'Requirements',
        'rekrutmen_pipeline_id' => $pipeline->id,
    ]);
});

it('pins legacy thumbnail storage before configuration changes and does not follow duplicate paths', function (): void {
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/legacy.jpg';
    Storage::disk('public')->put($path, 'original');
    JobPosting::query()->whereKey($this->posting->id)->update(['thumbnail_path' => $path]);
    $posting = $this->posting->fresh();

    expect($posting->thumbnail_url)->toBe(Storage::disk('public')->url($path))
        ->and($posting->fresh()->thumbnail_disk)->toBe('public');

    Storage::disk('local')->put($path, 'unrelated');
    config(['rekrutmen.thumbnail_disk' => 'local']);

    expect($posting->fresh()->resolveThumbnailDisk())->toBe('public')
        ->and($posting->fresh()->thumbnail_url)->toBe(Storage::disk('public')->url($path));
});

it('uses temporary URL capabilities on a private disk with a custom name', function (): void {
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/private.jpg';
    Storage::disk('private_media')->put($path, 'private');
    Storage::disk('private_media')->buildTemporaryUrlsUsing(fn (string $file): string => 'https://private.example.test/temporary/'.$file);
    $this->posting->update(['thumbnail_path' => $path, 'thumbnail_disk' => 'private_media']);

    expect($this->posting->fresh()->thumbnail_url)->toBe('https://private.example.test/temporary/'.$path);
});

it('does not expose a public URL when the saved private disk has no temporary URL capability', function (): void {
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/private.jpg';
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('exists')->with($path)->andReturnTrue();
    $filesystem->shouldReceive('providesTemporaryUrls')->andReturnFalse();
    $filesystem->shouldNotReceive('url');
    Storage::set('private_media', $filesystem);
    $this->posting->update(['thumbnail_path' => $path, 'thumbnail_disk' => 'private_media']);

    expect($this->posting->fresh()->thumbnail_url)->toBeNull();
});

it('deletes only the recorded thumbnail when replacing it across disks', function (): void {
    $oldPath = JobPosting::THUMBNAIL_DIRECTORY.'/old.jpg';
    $newPath = JobPosting::THUMBNAIL_DIRECTORY.'/new.jpg';
    Storage::disk('public')->put($oldPath, 'original');
    Storage::disk('local')->put($oldPath, 'unrelated');
    Storage::disk('local')->put($newPath, 'replacement');
    $this->posting->update(['thumbnail_path' => $oldPath, 'thumbnail_disk' => 'public']);
    $this->posting->update(['thumbnail_path' => $newPath, 'thumbnail_disk' => 'local']);

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('local')->assertExists([$oldPath, $newPath]);
    expect($this->posting->fresh()->thumbnail_disk)->toBe('local');
});

it('leaves ambiguous legacy thumbnail copies untouched on removal', function (): void {
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/ambiguous.jpg';
    Storage::disk('public')->put($path, 'first');
    Storage::disk('local')->put($path, 'second');
    JobPosting::query()->whereKey($this->posting->id)->update(['thumbnail_path' => $path]);
    $posting = $this->posting->fresh();

    expect($posting->resolveThumbnailDisk())->toBeNull()
        ->and($posting->thumbnail_url)->toBeNull();

    $posting->update(['thumbnail_path' => null]);
    Storage::disk('public')->assertExists($path);
    Storage::disk('local')->assertExists($path);
});

it('retains the old thumbnail in Filament then stores its replacement on the current disk', function (string $newDisk): void {
    $oldDisk = $newDisk === 'local' ? 'public' : 'local';
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/existing.jpg';
    Storage::disk($oldDisk)->put($path, 'original');
    $this->posting->update(['thumbnail_path' => $path, 'thumbnail_disk' => $oldDisk]);
    config(['rekrutmen.thumbnail_disk' => $newDisk]);

    $page = Livewire::test(EditJobPosting::class, ['record' => $this->posting->id])
        ->assertStatus(200)
        ->assertFormFieldExists('thumbnail_path', fn (FileUpload $field): bool => $field->getDiskName() === $oldDisk)
        ->fillForm(['title' => 'Updated title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->posting->fresh()->thumbnail_path)->toBe($path)
        ->and($this->posting->fresh()->thumbnail_disk)->toBe($oldDisk);

    $page->fillForm(['thumbnail_path' => []])
        ->fillForm(['thumbnail_path' => UploadedFile::fake()->image('replacement.png')])
        ->call('save')
        ->assertStatus(200)
        ->assertHasNoFormErrors();

    $posting = $this->posting->fresh();
    expect($posting->thumbnail_disk)->toBe($newDisk)
        ->and(Storage::disk($newDisk)->getVisibility($posting->thumbnail_path))->toBe($newDisk === 'public' ? 'public' : 'private');
    Storage::disk($newDisk)->assertExists($posting->thumbnail_path);
    Storage::disk($oldDisk)->assertMissing($path);
})->with(['local', 'public']);

it('does not clear an unavailable saved thumbnail during an unrelated Filament edit', function (): void {
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/temporarily-unavailable.jpg';
    $this->posting->update(['thumbnail_path' => $path, 'thumbnail_disk' => 'private_media']);

    Livewire::test(EditJobPosting::class, ['record' => $this->posting->id])
        ->assertStatus(200)
        ->fillForm(['title' => 'Keep attachment'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->posting->fresh()->thumbnail_path)->toBe($path)
        ->and($this->posting->fresh()->thumbnail_disk)->toBe('private_media');
});

it('ignores forged thumbnail disk metadata on an unchanged file', function (): void {
    $path = JobPosting::THUMBNAIL_DIRECTORY.'/same-name.jpg';
    Storage::disk('public')->put($path, 'owned file');
    Storage::disk('local')->put($path, 'unrelated file');
    $this->posting->update(['thumbnail_path' => $path, 'thumbnail_disk' => 'public']);

    Livewire::test(EditJobPosting::class, ['record' => $this->posting->id])
        ->set('data.thumbnail_disk', 'local')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->posting->fresh()->thumbnail_disk)->toBe('public');
    Storage::disk('public')->assertExists($path);
    Storage::disk('local')->assertExists($path);
});

it('reads existing candidate uploads on their saved disk and writes replacements to the configured disk', function (string $newDisk): void {
    $oldDisk = $newDisk === 'local' ? 'public' : 'local';
    config(['rekrutmen.disk' => $oldDisk]);
    $photo = JobApplication::PHOTO_DIRECTORY.'/existing.jpg';
    $resume = JobApplication::RESUME_DIRECTORY.'/existing.pdf';
    Storage::disk($oldDisk)->put($photo, 'photo');
    Storage::disk($oldDisk)->put($resume, 'resume');
    $application = JobApplication::query()->create([
        'job_posting_id'             => $this->posting->id,
        'full_name'                  => 'Storage Candidate',
        'email'                      => 'storage@example.test',
        'gender'                     => JobApplicationGender::Female,
        'birth_date'                 => '1999-01-10',
        'marital_status'             => JobApplicationMaritalStatus::Single,
        'address_ktp'                => 'Jakarta',
        'address_domicile'           => 'Jakarta',
        'whatsapp_number'            => '081234567890',
        'active_phone'               => '081234567890',
        'emergency_contact_name'     => 'Family',
        'emergency_contact_relation' => 'Sibling',
        'emergency_contact_phone'    => '081111111111',
        'photo_path'                 => $photo,
        'photo_disk'                 => $oldDisk,
        'resume_path'                => $resume,
        'resume_disk'                => $oldDisk,
        'status'                     => JobApplicationStatus::IN_PROGRESS,
    ])->fresh();
    $oldPhoto = $application->photo_path;
    $oldResume = $application->resume_path;
    config(['rekrutmen.disk' => $newDisk]);

    $page = Livewire::test(EditJobApplication::class, ['record' => $application->id])
        ->assertStatus(200)
        ->assertFormFieldExists('photo_path', fn (FileUpload $field): bool => $field->getDiskName() === $oldDisk)
        ->assertFormFieldExists('resume_path', fn (FileUpload $field): bool => $field->getDiskName() === $oldDisk)
        ->fillForm(['full_name' => 'Updated Candidate'])
        ->set('data.photo_disk', $newDisk)
        ->set('data.resume_disk', $newDisk)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($application->fresh()->photo_disk)->toBe($oldDisk)
        ->and($application->fresh()->resume_disk)->toBe($oldDisk);

    $page->fillForm(['photo_path' => [], 'resume_path' => []])->fillForm([
        'photo_path'  => UploadedFile::fake()->image('new-photo.png'),
        'resume_path' => UploadedFile::fake()->create('new-resume.pdf', 10, 'application/pdf'),
    ])->call('save')->assertStatus(200)->assertHasNoFormErrors();

    $application->refresh();
    expect($application->photo_disk)->toBe($newDisk)
        ->and($application->resume_disk)->toBe($newDisk)
        ->and(Storage::disk($newDisk)->getVisibility($application->photo_path))->toBe($newDisk === 'public' ? 'public' : 'private')
        ->and(Storage::disk($newDisk)->getVisibility($application->resume_path))->toBe($newDisk === 'public' ? 'public' : 'private');
    Storage::disk($newDisk)->assertExists([$application->photo_path, $application->resume_path]);
    Storage::disk($oldDisk)->assertMissing([$oldPhoto, $oldResume]);
})->with(['local', 'public']);
