<?php

use Cesa\Rekrutmen\Jobs\QueueCandidateCvScreeningBatchJob;
use Cesa\Rekrutmen\Jobs\ScreenCandidateCvJob;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Services\AiScreeningService;
use Cesa\Rekrutmen\Services\CvTextExtractor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    config([
        'rekrutmen.disk'                       => 'public',
        'services.openai_compatible.automatic' => true,
        'services.openai_compatible.base_url'  => 'https://screening.example.test/v1',
        'services.openai_compatible.api_key'   => 'test-api-key',
        'services.openai_compatible.model'     => 'test-model',
        'queue.default'                        => 'sync',
    ]);
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['screening.example.test/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'score'   => 87, 'recommendation' => 'invalid-provider-label',
            'summary' => 'Keahlian Laravel dan pengalaman backend memenuhi persyaratan. Konfirmasi pengalaman memimpin tim saat wawancara.',
        ])]]],
    ])]);
});

function createScreeningCandidate(array $attributes = [], ?string $content = null): JobApplication
{
    $posting = JobPosting::query()->create([
        'title'        => 'Backend Developer', 'slug' => 'backend-'.Str::uuid(),
        'requirements' => 'Laravel, SQL, pengalaman 3 tahun',
        'description'  => 'Membangun aplikasi backend',
        'is_published' => true,
    ]);
    $path = 'rekrutmen/cv/'.Str::uuid().'.pdf';
    Storage::disk('public')->put($path, $content ?? screeningPdf());

    return JobApplication::query()->create(array_merge([
        'job_posting_id' => $posting->id,
        'full_name'      => 'Candidate Screening',
        'email'          => Str::uuid().'@example.test',
        'status'         => 'in_progress',
        'resume_path'    => $path,
        'resume_disk'    => 'public',
    ], $attributes));
}

function screeningPdf(string $text = 'Experienced Laravel backend engineer with SQL skills and five years of relevant development experience.'): string
{
    $stream = gzcompress('BT ('.$text.') Tj ET');

    return "%PDF-1.4\n1 0 obj\n<< /Filter /FlateDecode /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream\nendobj";
}

it('queues a new application after its managed CV path is saved without HTTP work', function (): void {
    $candidate = createScreeningCandidate();

    expect($candidate->fresh()->ai_screening_status)->toBe('queued')
        ->and($candidate->resume_path)->toStartWith('rekrutmen/cv/CV-'.$candidate->id.'-');
    Storage::disk('public')->assertExists($candidate->resume_path);
    Queue::assertPushed(ScreenCandidateCvJob::class, function (ScreenCandidateCvJob $job) use ($candidate): bool {
        return $job->applicationId === $candidate->id && $job->connection === 'database'
            && $job->afterCommit === true && $job->force === false;
    });
    Http::assertNothingSent();
});

it('does not queue duplicate saves or duplicate manual requests', function (): void {
    $candidate = createScreeningCandidate();
    $candidate->update(['full_name' => 'Updated Candidate']);

    expect(app(AiScreeningService::class)->queue($candidate, force: true))->toBeFalse();
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
});

it('supersedes queued requests when a CV is replaced and never processes the obsolete token', function (): void {
    $candidate = createScreeningCandidate();
    $oldToken = $candidate->ai_screening_token;
    Storage::disk('public')->put('rekrutmen/cv/replaced.pdf', screeningPdf('PHP developer with seven years experience building Laravel services and REST APIs.'));
    $candidate->update(['resume_path' => 'rekrutmen/cv/replaced.pdf']);

    expect($candidate->ai_screening_token)->not->toBe($oldToken);
    app(AiScreeningService::class)->process($candidate->id, $oldToken);
    Queue::assertPushed(ScreenCandidateCvJob::class, 2);
    Http::assertNothingSent();
});

it('does not dispatch a screening job for a rolled back application', function (): void {
    $connection = (new JobApplication)->getConnection();
    $connection->beginTransaction();
    $candidate = createScreeningCandidate();
    Queue::assertNothingPushed();
    $connection->rollBack();

    expect(JobApplication::query()->find($candidate->id))->toBeNull();
    Queue::assertNothingPushed();
});

it('allows manual screening when automatic screening is disabled', function (): void {
    config(['services.openai_compatible.automatic' => false]);
    $candidate = createScreeningCandidate();
    Queue::assertNothingPushed();
    expect($candidate->fresh()->ai_screening_status)->toBe('pending');

    expect(app(AiScreeningService::class)->queue($candidate))->toBeTrue();
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
});

it('leaves unconfigured screening pending without disrupting intake', function (): void {
    config(['services.openai_compatible.api_key' => '']);
    $candidate = createScreeningCandidate();

    expect($candidate->fresh()->ai_screening_status)->toBe('pending');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('stores validated AI results using professional criteria and normalizes the recommendation', function (): void {
    $candidate = createScreeningCandidate();
    app(AiScreeningService::class)->process($candidate->id, $candidate->ai_screening_token);
    $candidate->refresh();

    expect($candidate->ai_screening_status)->toBe('completed')
        ->and($candidate->ai_match_score)->toBe(87)
        ->and($candidate->ai_recommendation)->toBe('Direkomendasikan')
        ->and($candidate->ai_analyzed_at)->not->toBeNull()
        ->and(strlen($candidate->ai_screening_fingerprint))->toBe(64);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://screening.example.test/v1/chat/completions'
        && $request['model'] === 'test-model'
        && str_contains($request['messages'][1]['content'], 'Experienced Laravel backend engineer')
        && str_contains($request['messages'][0]['content'], 'Jangan menilai berdasarkan jenis kelamin'));
});

it('reuses successful results when an identical CV is uploaded again', function (): void {
    $candidate = createScreeningCandidate();
    $service = app(AiScreeningService::class);
    $service->process($candidate->id, $candidate->ai_screening_token);
    $candidate->refresh();
    Storage::disk('public')->put('rekrutmen/cv/same-cv.pdf', screeningPdf());
    $candidate->update(['resume_path' => 'rekrutmen/cv/same-cv.pdf']);
    $service->process($candidate->id, $candidate->ai_screening_token);

    expect($candidate->fresh()->ai_screening_status)->toBe('completed');
    Http::assertSentCount(1);
});

it('reanalyzes changed requirements and allows an explicit forced rerun', function (): void {
    $candidate = createScreeningCandidate();
    $service = app(AiScreeningService::class);
    $service->process($candidate->id, $candidate->ai_screening_token);
    $candidate->refresh();
    $candidate->jobPosting->update(['requirements' => 'Laravel, SQL, leadership, 10 years experience']);
    expect($service->queue($candidate, force: true))->toBeTrue();
    $service->process($candidate->id, $candidate->ai_screening_token);
    $candidate->refresh();
    expect($service->queue($candidate, force: true))->toBeTrue();
    $service->process($candidate->id, $candidate->ai_screening_token, force: true);

    Http::assertSentCount(3);
});

it('marks unreadable CVs for human review without inventing a zero score', function (): void {
    $candidate = createScreeningCandidate(content: '%PDF-1.4 image-only-document');
    app(AiScreeningService::class)->process($candidate->id, $candidate->ai_screening_token);

    expect($candidate->fresh()->ai_screening_status)->toBe('needs_review')
        ->and($candidate->fresh()->ai_match_score)->toBeNull();
    Http::assertNothingSent();
});

it('does not overwrite a newer CV when an older API request finishes', function (): void {
    $candidate = createScreeningCandidate();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function () use ($candidate) {
        Storage::disk('public')->put('rekrutmen/cv/newer.pdf', screeningPdf('New candidate CV with updated SQL and Laravel technical skills and experience.'));
        $candidate->refresh()->update(['resume_path' => 'rekrutmen/cv/newer.pdf']);

        return Http::response(['choices' => [['message' => ['content' => '{"score":95,"summary":"Old CV response"}']]]]);
    });
    app(AiScreeningService::class)->process($candidate->id, $candidate->ai_screening_token);

    expect($candidate->fresh()->ai_screening_status)->toBe('queued')
        ->and($candidate->fresh()->ai_match_score)->toBeNull();
});

it('retries provider failures with sanitized errors then marks exhausted jobs failed', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'secret-provider-response'], 429)]);
    $candidate = createScreeningCandidate();
    $job = new ScreenCandidateCvJob($candidate->id, $candidate->ai_screening_token);

    expect(fn () => $job->handle(app(AiScreeningService::class)))
        ->toThrow(RuntimeException::class, 'Layanan AI belum berhasil memproses CV.');
    expect($candidate->fresh()->ai_screening_status)->toBe('queued')
        ->and($candidate->fresh()->ai_match_score)->toBeNull()
        ->and($candidate->fresh()->ai_screening_error)->not->toContain('secret-provider-response');
    $job->failed(new RuntimeException('secret-provider-response'));
    expect($candidate->fresh()->ai_screening_status)->toBe('failed')
        ->and($job->backoff())->toBe([15, 45, 120])
        ->and($job->timeout)->toBeLessThan(90);
});

it('rejects malformed or out of range provider output without fallback scores', function (string $response): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $response]]]])]);
    $candidate = createScreeningCandidate();

    expect(fn () => app(AiScreeningService::class)->process($candidate->id, $candidate->ai_screening_token))
        ->toThrow(RuntimeException::class);
    expect($candidate->fresh()->ai_match_score)->toBeNull();
})->with(['invalid JSON', '{"score":101,"summary":"Too high"}', '{"score":-1,"summary":"Too low"}', '{"score":70,"summary":""}']);

it('extracts plain text and all text in supported PDF streams', function (): void {
    $text = 'Developer with extensive Laravel and SQL experience and clear technical qualifications.';
    expect(app(CvTextExtractor::class)->extract($text))->toBe($text)
        ->and(app(CvTextExtractor::class)->extract(screeningPdf($text)))->toContain($text);
});

it('accepts fenced JSON even when the provider appends a processing note', function (): void {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['choices' => [['message' => [
        'content' => "```json\n{\"score\":82,\"summary\":\"Keahlian utama sesuai; konfirmasi pengalaman saat wawancara.\"}\n```\n\nskipped: provider processing note",
    ]]]])]);
    $candidate = createScreeningCandidate();
    app(AiScreeningService::class)->process($candidate->id, $candidate->ai_screening_token);

    expect($candidate->fresh()->ai_screening_status)->toBe('completed')
        ->and($candidate->fresh()->ai_match_score)->toBe(82);
});

it('invalidates an existing result if CV changes while automatic screening is disabled', function (): void {
    $candidate = createScreeningCandidate();
    $service = app(AiScreeningService::class);
    $oldToken = $candidate->ai_screening_token;
    $service->process($candidate->id, $oldToken);
    $candidate->refresh();
    config(['services.openai_compatible.automatic' => false]);
    $candidate->update(['resume_path' => null]);

    expect($candidate->fresh()->ai_screening_status)->toBe('pending')
        ->and($candidate->fresh()->ai_screening_token)->toBeNull();
    $service->fail($candidate->id, $oldToken);
    expect($candidate->fresh()->ai_screening_status)->toBe('pending');
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
});

it('persists a sanitized failure when the queue cannot accept work', function (): void {
    $this->mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('sensitive queue credentials'));
    });
    $candidate = createScreeningCandidate();

    expect($candidate->fresh()->ai_screening_status)->toBe('failed')
        ->and($candidate->fresh()->ai_screening_error)->not->toContain('credentials');
    Http::assertNothingSent();
});

it('queues permitted candidates from the batch worker and skips candidates outside the actor scope', function (): void {
    config(['services.openai_compatible.automatic' => false]);
    $actor = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    Permission::findOrCreate('update_rekrutmen_job::application', 'web');
    $actor->givePermissionTo('update_rekrutmen_job::application');
    $allowed = createScreeningCandidate(['creator_id' => $actor->id]);
    $forbidden = createScreeningCandidate(['creator_id' => User::factory()->create()->id]);
    $job = new QueueCandidateCvScreeningBatchJob([$allowed->id, $forbidden->id], $actor->id, false, now()->toDateTimeString());

    $job->handle(app(AiScreeningService::class));

    expect($allowed->fresh()->ai_screening_status)->toBe('queued')
        ->and($forbidden->fresh()->ai_screening_status)->toBe('pending');
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
    Http::assertNothingSent();
});

it('does not queue batch candidates after the requesting actor permission is revoked', function (): void {
    config(['services.openai_compatible.automatic' => false]);
    $actor = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    Permission::findOrCreate('update_rekrutmen_job::application', 'web');
    $actor->givePermissionTo('update_rekrutmen_job::application');
    $candidate = createScreeningCandidate(['creator_id' => $actor->id]);
    $job = new QueueCandidateCvScreeningBatchJob([$candidate->id], $actor->id, false, now()->toDateTimeString());
    $actor->revokePermissionTo('update_rekrutmen_job::application');

    $job->handle(app(AiScreeningService::class));

    expect($candidate->fresh()->ai_screening_status)->toBe('pending');
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    Http::assertNothingSent();
});

it('does not repeat paid screening when a forced batch retries after its candidates completed', function (): void {
    config(['services.openai_compatible.automatic' => false]);
    $actor = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    Permission::findOrCreate('update_rekrutmen_job::application', 'web');
    $actor->givePermissionTo('update_rekrutmen_job::application');
    $candidate = createScreeningCandidate(['creator_id' => $actor->id]);
    $job = new QueueCandidateCvScreeningBatchJob([$candidate->id], $actor->id, true, now()->format('Y-m-d H:i:s.u'));
    $service = app(AiScreeningService::class);
    $job->handle($service);
    $candidate->refresh();
    $service->process($candidate->id, $candidate->ai_screening_token, force: true);
    $token = $candidate->ai_screening_token;

    $job->handle($service);

    expect($candidate->fresh()->ai_screening_status)->toBe('completed')
        ->and($candidate->fresh()->ai_screening_token)->toBe($token);
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
    Http::assertSentCount(1);
});
