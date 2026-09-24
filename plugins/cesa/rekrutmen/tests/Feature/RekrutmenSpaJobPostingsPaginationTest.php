<?php

use Cesa\Rekrutmen\Models\JobPosting;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    $user = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->givePermissionTo(Permission::findOrCreate('view_any_rekrutmen_job::posting', 'web'));
    $this->actingAs($user);
});

it('returns every matching job posting across pages with deterministic ordering', function (): void {
    $this->freezeTime();

    $postingIds = collect();

    foreach (range(1, 451) as $number) {
        $posting = JobPosting::query()->create([
            'title'        => 'PaginationBatch Sales '.$number,
            'slug'         => 'pagination-batch-sales-'.$number,
            'is_published' => true,
        ]);

        $postingIds->push($posting->getKey());
    }

    JobPosting::query()->create([
        'title'        => 'Unrelated recruitment posting',
        'slug'         => 'unrelated-recruitment-posting',
        'is_published' => true,
    ]);

    $deletedPosting = JobPosting::query()->create([
        'title'        => 'PaginationBatch deleted posting',
        'slug'         => 'pagination-batch-deleted-posting',
        'is_published' => true,
    ]);
    $deletedPosting->delete();

    $returnedIds = collect();

    foreach (range(1, 5) as $page) {
        $response = $this->getJson(route('rekrutmen.api.job-postings', [
            'search'   => 'PaginationBatch',
            'per_page' => 100,
            'page'     => $page,
        ]))
            ->assertSuccessful()
            ->assertJsonPath('current_page', $page)
            ->assertJsonPath('per_page', 100)
            ->assertJsonPath('total', 451)
            ->assertJsonPath('last_page', 5)
            ->assertJsonCount($page === 5 ? 51 : 100, 'data');

        $returnedIds->push(...array_column($response->json('data'), 'id'));
    }

    expect($returnedIds->all())->toBe($postingIds->reverse()->values()->all())
        ->and($returnedIds->unique())->toHaveCount(451);
});

it('accepts null and blank searches when job posting fields are nullable', function (?string $search): void {
    $posting = JobPosting::query()->create([
        'title'        => 'Sales Nullable Fields',
        'slug'         => 'sales-nullable-fields',
        'description'  => null,
        'requirements' => null,
        'location'     => null,
        'company_id'   => null,
        'is_published' => true,
    ]);

    $this->json('GET', route('rekrutmen.api.job-postings'), ['search' => $search])
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $posting->getKey())
        ->assertJsonPath('data.0.description', null)
        ->assertJsonPath('data.0.requirements', null)
        ->assertJsonPath('data.0.company_id', null)
        ->assertJsonPath('data.0.location', 'Indonesia');
})->with([
    'null search'       => null,
    'empty search'      => '',
    'whitespace search' => '   ',
]);

it('finds a nullable job posting beyond the first fifty records', function (): void {
    $this->freezeTime();

    $target = JobPosting::query()->create([
        'title'        => 'Sales Nullable Target',
        'slug'         => 'sales-nullable-target',
        'description'  => null,
        'requirements' => null,
        'location'     => null,
        'company_id'   => null,
        'is_published' => true,
    ]);

    foreach (range(1, 60) as $number) {
        JobPosting::query()->create([
            'title'        => 'Other Sales '.$number,
            'slug'         => 'other-sales-'.$number,
            'is_published' => true,
        ]);
    }

    $firstPage = $this->getJson(route('rekrutmen.api.job-postings'))
        ->assertSuccessful()
        ->assertJsonPath('total', 61)
        ->assertJsonCount(50, 'data');

    expect(array_column($firstPage->json('data'), 'id'))->not->toContain($target->getKey());

    $this->getJson(route('rekrutmen.api.job-postings', ['search' => '  Nullable Target  ']))
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $target->getKey())
        ->assertJsonPath('data.0.description', null)
        ->assertJsonPath('data.0.requirements', null)
        ->assertJsonPath('data.0.company_id', null);
});
