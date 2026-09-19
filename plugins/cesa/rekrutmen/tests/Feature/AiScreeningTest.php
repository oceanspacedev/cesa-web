<?php

use Cesa\Rekrutmen\Jobs\QueueCandidateCvScreeningBatchJob;
use Cesa\Rekrutmen\Jobs\ScreenCandidateCvJob;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Services\AiScreeningService;
use Cesa\Rekrutmen\Services\AiSettingsService;
use Dompdf\Dompdf;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    config([
        'services.openai_compatible.base_url'  => 'https://screening.example.test/v1/',
        'services.openai_compatible.api_key'   => 'test-environment-key',
        'services.openai_compatible.model'     => 'test-screening-model',
        'services.openai_compatible.automatic' => false,
        'services.gemini.api_key'              => 'unused-gemini-key',
        'rekrutmen.disk'                       => 'local',
    ]);
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
    Storage::fake('local');
});

function aiScreeningOperator(bool $canManageAi = true): User
{
    $user = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $permissions = ['view_any_rekrutmen_job::application', 'view_rekrutmen_job::application', 'update_rekrutmen_job::application'];
    if ($canManageAi) {
        $permissions[] = 'manage_rekrutmen_ai';
    }
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    return $user;
}

function aiScreeningStoredKey(string $key, string $name = 'openai_compatible_api_key'): SettingsProperty
{
    return SettingsProperty::query()->create([
        'group' => 'rekrutmen', 'name' => $name, 'payload' => json_encode($key), 'locked' => false,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function aiScreeningCandidate(array $attributes = [], ?JobPosting $job = null): JobApplication
{
    $job ??= JobPosting::query()->create([
        'title'        => 'Laravel Developer', 'slug' => 'ai-screening-'.str()->uuid(),
        'requirements' => 'Pengalaman PHP, Laravel, dan pengujian aplikasi.', 'is_published' => false,
    ]);
    $application = JobApplication::query()->create([
        'job_posting_id' => $job->id, 'full_name' => 'Screening Candidate',
        'email'          => fake()->unique()->safeEmail(), 'status' => 'in_progress',
    ]);
    $pdf = new Dompdf;
    $pdf->loadHtml('<html><body style="font-family: Helvetica">Experienced PHP and Laravel developer with five years of application testing and database design.</body></html>');
    $pdf->render();
    $path = 'rekrutmen/cv/CV-'.$application->id.'-screening.pdf';
    Storage::disk('local')->put($path, $pdf->output());
    JobApplication::query()->whereKey($application->id)->update(array_merge([
        'resume_path' => $path, 'resume_disk' => 'local', 'ai_screening_status' => 'pending',
    ], $attributes));

    return $application->refresh();
}

it('returns provider preferences without disclosing configured or legacy Gemini keys', function (): void {
    $this->actingAs(aiScreeningOperator());
    aiScreeningStoredKey('old-gemini-database-key', 'gemini_api_key');
    $response = $this->getJson('/rekrutmen/api/settings/ai')->assertSuccessful()->assertJson([
        'provider'    => 'openai_compatible', 'automatic' => false,
        'base_url'    => 'https://screening.example.test/v1', 'model' => 'test-screening-model',
        'has_api_key' => true, 'is_database' => false, 'has_env' => true,
    ])->assertJsonStructure(['updated_at'])->assertDontSee('test-environment-key')->assertDontSee('old-gemini-database-key');

    expect($response->json('api_key'))->toBeFalsy()
        ->and(app(AiSettingsService::class)->current()['api_key'])->toBe('test-environment-key');
    config(['services.openai_compatible.api_key' => null]);
    $this->getJson('/rekrutmen/api/settings/ai')->assertSuccessful()->assertJsonPath('has_api_key', false);
});

it('supports a legacy OpenAI compatible key until preferences are saved', function (): void {
    $this->actingAs(aiScreeningOperator());
    aiScreeningStoredKey('legacy-router-key');
    $this->getJson('/rekrutmen/api/settings/ai')->assertSuccessful()
        ->assertJsonPath('has_api_key', true)->assertJsonPath('is_database', true)->assertDontSee('legacy-router-key');
    expect(app(AiSettingsService::class)->current()['api_key'])->toBe('legacy-router-key');
});

it('saves preferences with an encrypted key and returns only masked key state', function (): void {
    $this->actingAs(aiScreeningOperator());
    $this->postJson('/rekrutmen/api/settings/ai', [
        'automatic' => true, 'base_url' => 'https://new-router.example.test/v1/',
        'model'     => 'new-model', 'api_key' => '  test-database-key  ',
    ])->assertSuccessful()->assertJsonPath('success', true)->assertDontSee('test-database-key');
    $setting = SettingsProperty::query()->where('group', 'rekrutmen')->where('name', 'openai_compatible_settings')->firstOrFail();
    $payload = json_decode($setting->payload, true);
    expect($setting->payload)->not->toContain('test-database-key')
        ->and(Crypt::decryptString($payload['api_key']))->toBe('test-database-key')
        ->and($payload['automatic'])->toBeTrue()->and($payload['model'])->toBe('new-model')
        ->and(app(AiSettingsService::class)->current()['api_key'])->toBe('test-database-key');
    $response = $this->getJson('/rekrutmen/api/settings/ai')->assertSuccessful()->assertJson([
        'automatic'   => true, 'base_url' => 'https://new-router.example.test/v1', 'model' => 'new-model',
        'has_api_key' => true, 'is_database' => true,
    ])->assertDontSee('test-database-key');
    expect($response->json('api_key'))->toBeFalsy();
});

it('preserves the current key when a blank key is saved and encrypts environment fallback', function (?string $key, bool $legacy): void {
    $this->actingAs(aiScreeningOperator());
    if ($legacy) {
        aiScreeningStoredKey('legacy-router-key');
    }
    $expectedKey = $legacy ? 'legacy-router-key' : 'test-environment-key';
    $this->postJson('/rekrutmen/api/settings/ai', [
        'automatic' => true, 'base_url' => 'https://screening.example.test/v1',
        'model'     => 'test-screening-model', 'api_key' => $key,
    ])->assertSuccessful();
    $payload = json_decode(SettingsProperty::query()->where('group', 'rekrutmen')->where('name', 'openai_compatible_settings')->value('payload'), true);
    expect(Crypt::decryptString($payload['api_key']))->toBe($expectedKey)
        ->and(app(AiSettingsService::class)->current()['api_key'])->toBe($expectedKey);
    config(['services.openai_compatible.api_key' => 'changed-environment-key']);
    expect(app(AiSettingsService::class)->current()['api_key'])->toBe($expectedKey);
})->with(['blank environment key' => ['', false], 'null environment key' => [null, false], 'whitespace legacy key' => ['   ', true]]);

it('explicitly clears the key without falling back to environment or legacy credentials', function (): void {
    $this->actingAs(aiScreeningOperator());
    aiScreeningStoredKey('legacy-router-key');
    aiScreeningStoredKey('legacy-gemini-key', 'gemini_api_key');
    $this->postJson('/rekrutmen/api/settings/ai', [
        'automatic' => false, 'base_url' => 'https://screening.example.test/v1',
        'model'     => 'test-screening-model', 'clear_api_key' => true,
    ])->assertSuccessful();
    $this->getJson('/rekrutmen/api/settings/ai')->assertSuccessful()->assertJsonPath('has_api_key', false);
    expect(app(AiSettingsService::class)->current()['api_key'])->toBeFalsy();
    $this->postJson('/rekrutmen/api/settings/ai/test', [])->assertUnprocessable();
    Http::assertNothingSent();
});

it('validates provider keys and endpoints before saving or testing', function (array $invalid, string $field): void {
    $this->actingAs(aiScreeningOperator());
    foreach (['/rekrutmen/api/settings/ai', '/rekrutmen/api/settings/ai/test'] as $path) {
        $this->postJson($path, array_merge([
            'automatic' => true, 'base_url' => 'https://screening.example.test/v1', 'model' => 'test-screening-model',
        ], $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseMissing('settings', ['group' => 'rekrutmen', 'name' => 'openai_compatible_settings']);
    Http::assertNothingSent();
})->with([
    'array key'          => [['api_key' => ['unexpected']], 'api_key'],
    'integer key'        => [['api_key' => 12345], 'api_key'],
    'oversized key'      => [['api_key' => str_repeat('k', 2049)], 'api_key'],
    'HTTP endpoint'      => [['base_url' => 'http://screening.example.test/v1'], 'base_url'],
    'malformed endpoint' => [['base_url' => 'not-a-url'], 'base_url'],
    'oversized model'    => [['model' => str_repeat('m', 256)], 'model'],
]);

it('validates the automatic screening preference', function (): void {
    $this->actingAs(aiScreeningOperator());
    $this->postJson('/rekrutmen/api/settings/ai', [
        'automatic' => 'sometimes', 'base_url' => 'https://screening.example.test/v1', 'model' => 'test-screening-model',
    ])->assertUnprocessable()->assertJsonValidationErrors('automatic');
    Http::assertNothingSent();
});

it('tests unsaved endpoint model and key without replacing preferences', function (): void {
    $this->actingAs(aiScreeningOperator());
    aiScreeningStoredKey('legacy-router-key');
    Http::fake(['https://unsaved-router.example.test/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'Connection works.']]],
    ])]);
    $this->postJson('/rekrutmen/api/settings/ai/test', [
        'api_key' => 'test-unsaved-key', 'base_url' => 'https://unsaved-router.example.test/v1/', 'model' => 'unsaved-model',
    ])->assertSuccessful()->assertJsonPath('success', true);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://unsaved-router.example.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer test-unsaved-key') && $request['model'] === 'unsaved-model');
    expect(app(AiSettingsService::class)->current()['api_key'])->toBe('legacy-router-key');
    $this->assertDatabaseMissing('settings', ['group' => 'rekrutmen', 'name' => 'openai_compatible_settings']);
});

it('tests the current key when the supplied connection key is blank', function (): void {
    $this->actingAs(aiScreeningOperator());
    aiScreeningStoredKey('legacy-router-key');
    Http::fake(['https://screening.example.test/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '{"status":"OK"}']]],
    ])]);
    $this->postJson('/rekrutmen/api/settings/ai/test', ['api_key' => '   '])->assertSuccessful()->assertJsonPath('success', true);
    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer legacy-router-key'));
});

it('rejects connection tests without router credentials', function (): void {
    $this->actingAs(aiScreeningOperator());
    config(['services.openai_compatible.api_key' => null]);
    aiScreeningStoredKey('legacy-gemini-key', 'gemini_api_key');
    $this->postJson('/rekrutmen/api/settings/ai/test', [])->assertUnprocessable();
    Http::assertNothingSent();
});

it('reports connection failure without exposing upstream errors or credentials', function (): void {
    $this->actingAs(aiScreeningOperator());
    Http::fake(['https://screening.example.test/v1/chat/completions' => Http::response([
        'error' => ['message' => 'Rejected secret test-environment-key'],
    ], 401)]);
    $this->postJson('/rekrutmen/api/settings/ai/test', [])->assertBadRequest()->assertJsonPath('success', false)->assertDontSee('test-environment-key');
});

it('requires authentication for AI settings and screening endpoints', function (): void {
    $application = aiScreeningCandidate();
    $this->getJson('/rekrutmen/api/settings/ai')->assertUnauthorized();
    $this->postJson('/rekrutmen/api/settings/ai', ['api_key' => 'test-key'])->assertUnauthorized();
    $this->postJson('/rekrutmen/api/settings/ai/test', ['api_key' => 'test-key'])->assertUnauthorized();
    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/analyze-ai')->assertUnauthorized();
    $this->postJson('/rekrutmen/api/applications/batch-analyze-ai')->assertUnauthorized();
    $this->getJson('/rekrutmen/api/applications/ai-status')->assertUnauthorized();
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    Http::assertNothingSent();
});

it('requires AI management permission before exposing or changing provider settings', function (): void {
    $this->actingAs(aiScreeningOperator(false));
    aiScreeningStoredKey('stored-router-key');

    $this->getJson('/rekrutmen/api/settings/ai')->assertForbidden()->assertDontSee('stored-router-key');
    $this->postJson('/rekrutmen/api/settings/ai', [
        'automatic' => true,
        'base_url'  => 'https://untrusted.example.test/v1',
        'model'     => 'untrusted-model',
        'api_key'   => 'replacement-key',
    ])->assertForbidden();
    $this->postJson('/rekrutmen/api/settings/ai/test', [
        'base_url' => 'https://untrusted.example.test/v1',
        'model'    => 'untrusted-model',
        'api_key'  => '',
    ])->assertForbidden()->assertDontSee('stored-router-key');

    $this->assertDatabaseMissing('settings', ['group' => 'rekrutmen', 'name' => 'openai_compatible_settings']);
    expect(app(AiSettingsService::class)->current()['api_key'])->toBe('stored-router-key')
        ->and(app(AiSettingsService::class)->current()['base_url'])->toBe('https://screening.example.test/v1');
    Http::assertNothingSent();
});

it('queues manual screening when automatic screening is disabled without calling the router', function (): void {
    $this->actingAs(aiScreeningOperator());
    $application = aiScreeningCandidate();
    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/analyze-ai')->assertAccepted()
        ->assertJsonPath('success', true)->assertJsonPath('queued', true)->assertJsonPath('application.id', $application->id)
        ->assertJsonPath('application.ai_screening_status', 'queued')->assertJsonPath('application.ai_match_score', null);
    expect($application->fresh()->ai_screening_status)->toBe('queued');
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
    Http::assertNothingSent();
});

it('automatically queues an uploaded CV without waiting for the router', function (): void {
    $this->actingAs(aiScreeningOperator());
    $application = aiScreeningCandidate();
    $oldPath = $application->resume_path;
    $document = Storage::disk('local')->get($oldPath);
    config(['services.openai_compatible.automatic' => true]);

    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/upload-cv', [
        'cv' => UploadedFile::fake()->createWithContent('replacement.pdf', $document),
    ])->assertSuccessful()->assertJsonPath('success', true)
        ->assertJsonPath('ai_screening_status', 'queued')->assertJsonPath('ai_match_score', null);

    $application->refresh();
    expect($application->ai_screening_status)->toBe('queued')
        ->and($application->resume_path)->not->toBe($oldPath);
    Storage::disk('local')->assertExists($application->resume_path);
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
    Http::assertNothingSent();
});

it('does not duplicate a candidate already queued or processing', function (string $status): void {
    $this->actingAs(aiScreeningOperator());
    $application = aiScreeningCandidate(['ai_screening_status' => $status]);
    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/analyze-ai')->assertAccepted()->assertJsonPath('queued', false);
    expect($application->fresh()->ai_screening_status)->toBe($status);
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    Http::assertNothingSent();
})->with(['queued', 'processing']);

it('schedules a selected job for background queuing without dispatching candidate jobs in HTTP', function (): void {
    $operator = aiScreeningOperator();
    $this->actingAs($operator);
    $this->freezeTime();
    $first = aiScreeningCandidate();
    $job = $first->jobPosting;
    $applicationIds = [$first->id];
    foreach (range(1, 4) as $index) {
        $applicationIds[] = aiScreeningCandidate([], $job)->id;
    }
    $other = aiScreeningCandidate();
    $this->postJson('/rekrutmen/api/applications/batch-analyze-ai', ['job_id' => $job->id])
        ->assertAccepted()->assertJsonPath('queued', 5)->assertJsonPath('total', 5)->assertJsonPath('skipped', 0);
    Queue::assertPushed(QueueCandidateCvScreeningBatchJob::class, 1);
    Queue::assertPushed(QueueCandidateCvScreeningBatchJob::class, fn (QueueCandidateCvScreeningBatchJob $batch): bool => $batch->applicationIds === $applicationIds
        && $batch->actorId === $operator->id && $batch->force === false
        && $batch->requestedAt === now()->startOfSecond()->toIso8601String());
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    expect($first->fresh()->ai_screening_status)->toBe('pending')
        ->and($other->fresh()->ai_screening_status)->toBe('pending');

    Queue::pushed(QueueCandidateCvScreeningBatchJob::class)->first()->handle(app(AiScreeningService::class));
    Queue::assertPushed(ScreenCandidateCvJob::class, 5);
    expect($first->fresh()->ai_screening_status)->toBe('queued')
        ->and($other->fresh()->ai_screening_status)->toBe('pending');
    Http::assertNothingSent();
});

it('skips completed and in-flight candidates by default in a selected batch', function (): void {
    $this->actingAs(aiScreeningOperator());
    $applications = collect(['pending', 'completed', 'queued', 'processing', 'needs_review', 'failed'])
        ->map(fn (string $status): JobApplication => aiScreeningCandidate(['ai_screening_status' => $status]));
    $other = aiScreeningCandidate();
    $this->postJson('/rekrutmen/api/applications/batch-analyze-ai', ['application_ids' => $applications->pluck('id')->all()])
        ->assertAccepted()->assertJsonPath('queued', 3)->assertJsonPath('total', 6)->assertJsonPath('skipped', 3);
    $eligibleIds = $applications->filter(fn (JobApplication $application): bool => in_array($application->ai_screening_status, ['pending', 'needs_review', 'failed'], true))
        ->pluck('id')->values()->all();
    Queue::assertPushed(QueueCandidateCvScreeningBatchJob::class, 1);
    Queue::assertPushed(QueueCandidateCvScreeningBatchJob::class, fn (QueueCandidateCvScreeningBatchJob $batch): bool => $batch->applicationIds === $eligibleIds && $batch->force === false);
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    expect($other->fresh()->ai_screening_status)->toBe('pending');
    Http::assertNothingSent();
});

it('can force completed candidates back onto the queue without duplicating in-flight candidates', function (): void {
    $this->actingAs(aiScreeningOperator());
    $completed = aiScreeningCandidate([
        'ai_screening_status' => 'completed', 'ai_match_score' => 87, 'ai_recommendation' => 'Direkomendasikan',
        'ai_summary'          => 'Previous assessment.', 'ai_analyzed_at' => now()->subDay(),
    ]);
    $queued = aiScreeningCandidate(['ai_screening_status' => 'queued']);
    $this->postJson('/rekrutmen/api/applications/batch-analyze-ai', [
        'application_ids' => [$completed->id, $queued->id], 'force' => true,
    ])->assertAccepted()->assertJsonPath('queued', 1)->assertJsonPath('total', 2)->assertJsonPath('skipped', 1);
    Queue::assertPushed(QueueCandidateCvScreeningBatchJob::class, 1);
    Queue::assertPushed(QueueCandidateCvScreeningBatchJob::class, fn (QueueCandidateCvScreeningBatchJob $batch): bool => $batch->applicationIds === [$completed->id] && $batch->force === true);
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    expect($completed->fresh()->ai_screening_status)->toBe('completed');

    Queue::pushed(QueueCandidateCvScreeningBatchJob::class)->first()->handle(app(AiScreeningService::class));
    expect($completed->fresh()->ai_screening_status)->toBe('queued');
    Queue::assertPushed(ScreenCandidateCvJob::class, 1);
    Http::assertNothingSent();
});

it('chunks large batch requests into at most one hundred candidate IDs per coordinator', function (): void {
    $operator = aiScreeningOperator();
    $this->actingAs($operator);
    $first = aiScreeningCandidate();
    $job = $first->jobPosting;
    $applicationIds = [$first->id];
    foreach (range(1, 100) as $index) {
        $applicationIds[] = aiScreeningCandidate([], $job)->id;
    }

    $this->postJson('/rekrutmen/api/applications/batch-analyze-ai', ['job_id' => $job->id])
        ->assertAccepted()->assertJsonPath('queued', 101)->assertJsonPath('total', 101)->assertJsonPath('skipped', 0);

    $batches = Queue::pushed(QueueCandidateCvScreeningBatchJob::class);
    expect($batches)->toHaveCount(2)
        ->and($batches->map(fn (QueueCandidateCvScreeningBatchJob $batch): int => count($batch->applicationIds))->all())->toBe([100, 1])
        ->and($batches->flatMap(fn (QueueCandidateCvScreeningBatchJob $batch): array => $batch->applicationIds)->all())->toBe($applicationIds);
    foreach ($batches as $batch) {
        expect($batch->actorId)->toBe($operator->id)->and($batch->force)->toBeFalse();
    }
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    expect(JobApplication::query()->where('job_posting_id', $job->id)->where('ai_screening_status', 'pending')->count())->toBe(101);
    Http::assertNothingSent();
});

it('does not calculate scores or enqueue work when reading candidates', function (bool $filterJob): void {
    $this->actingAs(aiScreeningOperator());
    $application = aiScreeningCandidate();
    config(['services.openai_compatible.automatic' => true]);
    $path = '/rekrutmen/api/applications'.($filterJob ? '?job_id='.$application->job_posting_id : '');
    $this->getJson($path)->assertSuccessful()->assertJsonPath('applications.0.ai_match_score', null);
    expect($application->fresh()->ai_match_score)->toBeNull()->and($application->ai_analyzed_at)->toBeNull()
        ->and($application->ai_screening_status)->toBe('pending');
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    Http::assertNothingSent();
})->with(['all applications' => false, 'filtered job' => true]);

it('returns screening counts and hides previous scores for every noncompleted status', function (): void {
    $this->actingAs(aiScreeningOperator());
    $statuses = ['pending', 'queued', 'processing', 'completed', 'needs_review', 'failed'];
    foreach ($statuses as $status) {
        aiScreeningCandidate([
            'ai_screening_status' => $status, 'ai_match_score' => 87, 'ai_recommendation' => 'Direkomendasikan',
            'ai_summary'          => 'Previous assessment.', 'ai_analyzed_at' => now()->subDay(),
        ]);
    }
    $response = $this->getJson('/rekrutmen/api/applications/ai-status')->assertSuccessful()->assertJsonPath('total', 6);
    foreach ($statuses as $status) {
        $response->assertJsonPath('counts.'.$status, 1);
    }
    expect($response->json('applications'))->toHaveCount(6);
    foreach ($response->json('applications') as $application) {
        if ($application['ai_screening_status'] === 'completed') {
            expect($application['ai_match_score'])->toBe(87)->and($application['ai_summary'])->toBe('Previous assessment.');
        } else {
            expect($application['ai_match_score'])->toBeNull()->and($application['ai_recommendation'])->toBeNull()
                ->and($application['ai_summary'])->toBeNull();
        }
    }
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    Http::assertNothingSent();
});

it('keeps job-wide screening counts while filtering status rows to visible candidate IDs', function (): void {
    $this->actingAs(aiScreeningOperator());
    $selected = aiScreeningCandidate(['ai_screening_status' => 'queued']);
    aiScreeningCandidate(['ai_screening_status' => 'pending'], $selected->jobPosting);
    $otherJob = aiScreeningCandidate(['ai_screening_status' => 'completed']);
    $query = http_build_query(['job_id' => $selected->job_posting_id, 'ids' => [$selected->id, $otherJob->id]]);
    $this->getJson('/rekrutmen/api/applications/ai-status?'.$query)->assertSuccessful()
        ->assertJsonPath('total', 2)->assertJsonPath('counts.queued', 1)->assertJsonPath('counts.pending', 1)
        ->assertJsonPath('counts.completed', 0)->assertJsonCount(1, 'applications')->assertJsonPath('applications.0.id', $selected->id);
    Http::assertNothingSent();
});

it('requires candidate permissions for manual screening and status reads', function (): void {
    $application = aiScreeningCandidate();
    $this->actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson('/rekrutmen/api/applications/'.$application->id.'/analyze-ai')->assertForbidden();
    $this->postJson('/rekrutmen/api/applications/batch-analyze-ai', ['application_ids' => [$application->id]])->assertForbidden();
    $this->getJson('/rekrutmen/api/applications/ai-status')->assertForbidden();
    Queue::assertNotPushed(ScreenCandidateCvJob::class);
    Http::assertNothingSent();
});
