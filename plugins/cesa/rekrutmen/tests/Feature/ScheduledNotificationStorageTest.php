<?php

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Services\RekrutmenMailer;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Queue::fake();
    foreach (['s3', 'public', 'local'] as $disk) {
        Storage::fake($disk);
    }
    config(['rekrutmen.disk' => 's3', 'rekrutmen.thumbnail_disk' => 's3', 'filesystems.default' => 'local']);
});

function notificationStorageCandidate(): JobApplication
{
    $posting = JobPosting::query()->create([
        'title'        => 'Storage Engineer',
        'slug'         => 'notification-storage-'.Str::uuid(),
        'location'     => 'Jakarta',
        'is_published' => true,
    ]);

    return JobApplication::query()->create([
        'job_posting_id'  => $posting->id,
        'full_name'       => 'Storage Candidate',
        'email'           => 'storage@example.com',
        'whatsapp_number' => '081234567890',
        'status'          => 'in_progress',
    ]);
}

/** @param array<string, mixed> $overrides */
function notificationStorageBatch(?UploadedFile $attachment = null, array $overrides = []): ScheduledNotification
{
    return app(ScheduledNotificationService::class)->schedule(array_merge([
        'request_key'     => (string) Str::uuid(),
        'application_ids' => [notificationStorageCandidate()->id],
        'channels'        => ['email'],
        'subject'         => 'Panduan {nama_pelamar}',
        'body_message'    => 'Silakan membaca lampiran.',
    ], $overrides), $attachment, dispatch: false);
}

it('sends queued attachments from their saved disk after configuration changes', function (string $disk, string $nextDisk): void {
    config(['rekrutmen.disk' => $disk]);
    $contents = '%PDF-1.7 attachment from '.$disk;
    $batch = notificationStorageBatch(UploadedFile::fake()->createWithContent('instructions.pdf', $contents), [
        'scheduled_at' => now()->addHour(),
    ]);
    $path = $batch->attachment_path;
    $delivery = $batch->deliveries()->firstOrFail();
    expect($batch->attachment_disk)->toBe($disk)
        ->and($delivery->payload['attachment_disk'])->toBe($disk)
        ->and($delivery->payload['attachment_path'])->toBe($path)
        ->and(Storage::disk($disk)->get($path))->toBe($contents);

    Storage::disk($nextDisk)->put($path, 'unrelated same-path attachment');
    config(['rekrutmen.disk' => $nextDisk]);
    $this->mock(RekrutmenMailer::class, function (MockInterface $mock) use ($disk, $path, $contents): void {
        $mock->shouldReceive('send')->once()->andReturnUsing(function (string $view, array $data, Closure $callback) use ($disk, $path, $contents): void {
            Storage::disk($disk)->delete($path);
            $email = new Email;
            $callback(new Message($email));
            $attachments = $email->getAttachments();
            expect($attachments)->toHaveCount(1)
                ->and($attachments[0]->getBody())->toBe($contents)
                ->and($attachments[0]->getFilename())->toBe('instructions.pdf')
                ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf');
        });
    });

    $this->travel(61)->minutes();
    $service = app(ScheduledNotificationService::class);
    expect($service->executeScheduled($batch, true)['status'])->toBe('sent');
    $service->executeScheduled($batch, true);
    expect($delivery->refresh()->attempts)->toBe(1)
        ->and(Storage::disk($nextDisk)->get($path))->toBe('unrelated same-path attachment');
})->with([
    's3 to public'    => ['s3', 'public'],
    'public to local' => ['public', 'local'],
    'local to s3'     => ['local', 's3'],
]);

it('replays an attachment request without uploading again after a disk change', function (): void {
    $data = [
        'request_key'     => (string) Str::uuid(),
        'application_ids' => [notificationStorageCandidate()->id],
        'channels'        => ['email'],
        'subject'         => 'Panduan',
        'body_message'    => 'Lampiran',
    ];
    $contents = '%PDF-1.7 replayed attachment';
    $service = app(ScheduledNotificationService::class);
    $first = $service->schedule($data, UploadedFile::fake()->createWithContent('guide.pdf', $contents), dispatch: false);
    config(['rekrutmen.disk' => 'public']);
    $second = $service->schedule($data, UploadedFile::fake()->createWithContent('guide.pdf', $contents), dispatch: false);

    expect($second->id)->toBe($first->id)
        ->and($second->attachment_disk)->toBe('s3')
        ->and($second->attachment_path)->toBe($first->attachment_path)
        ->and($second->deliveries()->count())->toBe(1)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('keeps legacy batches and delivery snapshots on local storage', function (bool $legacyBatch): void {
    $batch = notificationStorageBatch();
    $path = 'rekrutmen/scheduled-attachments/legacy.pdf';
    Storage::disk('local')->put($path, 'legacy attachment');
    Storage::disk('s3')->put($path, 'unrelated cloud attachment');
    if ($legacyBatch) {
        $batch->update(['attachment_path' => $path, 'attachment_name' => 'legacy.pdf', 'attachment_disk' => null]);
        $batch->deliveries()->delete();
    } else {
        $delivery = $batch->deliveries()->firstOrFail();
        $payload = array_merge($delivery->payload, ['attachment_path' => $path, 'attachment_name' => 'legacy.pdf']);
        unset($payload['attachment_disk']);
        $delivery->update(['payload' => $payload]);
    }

    $this->mock(RekrutmenMailer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')->once()->andReturnUsing(function (string $view, array $data, Closure $callback): void {
            $email = new Email;
            $callback(new Message($email));
            expect($email->getAttachments()[0]->getBody())->toBe('legacy attachment');
        });
    });

    expect(app(ScheduledNotificationService::class)->executeScheduled($batch, true)['status'])->toBe('sent');
    if ($legacyBatch) {
        expect($batch->deliveries()->firstOrFail()->payload['attachment_disk'])->toBe('local');
    }
})->with(['legacy batch' => true, 'legacy delivery' => false]);

it('fails before mail submission when the recorded attachment is missing even with another disk collision', function (): void {
    $batch = notificationStorageBatch(UploadedFile::fake()->createWithContent('guide.pdf', '%PDF-1.7 guide'));
    Storage::disk('s3')->delete($batch->attachment_path);
    Storage::disk('public')->put($batch->attachment_path, 'unrelated public file');
    config(['rekrutmen.disk' => 'public']);
    $this->mock(RekrutmenMailer::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('failed')
        ->and($result['stats']['email_failed'])->toBe(1)
        ->and($result['stats']['email_unknown'])->toBe(0)
        ->and($batch->deliveries()->firstOrFail()->error_message)->toBe('Lampiran notifikasi tidak ditemukan.');
});

it('fails before mail submission when remote attachment content cannot be read', function (): void {
    $batch = notificationStorageBatch(UploadedFile::fake()->createWithContent('guide.pdf', '%PDF-1.7 guide'));
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('exists')->once()->with($batch->attachment_path)->andReturn(true);
    $filesystem->shouldReceive('get')->once()->with($batch->attachment_path)->andThrow(new RuntimeException('Remote read failed'));
    Storage::shouldReceive('disk')->with('s3')->andReturn($filesystem);
    $this->mock(RekrutmenMailer::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('failed')
        ->and($result['stats']['email_unknown'])->toBe(0)
        ->and($batch->deliveries()->firstOrFail()->error_message)->toBe('Remote read failed');
});

it('preserves an uncertain mail outcome after reading the attachment without resending', function (): void {
    $batch = notificationStorageBatch(UploadedFile::fake()->createWithContent('guide.pdf', '%PDF-1.7 guide'));
    $this->mock(RekrutmenMailer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('Mail transport interrupted'));
    });

    $service = app(ScheduledNotificationService::class);
    expect($service->executeScheduled($batch, true)['status'])->toBe('unknown');
    $service->executeScheduled($batch, true);
    expect($batch->deliveries()->firstOrFail()->attempts)->toBe(1);
});
