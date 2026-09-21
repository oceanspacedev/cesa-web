<?php

use Cesa\IdCard\Livewire\PublicIdCardRequestForm;
use Cesa\IdCard\Models\IdCardRequest;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

it('renders the public filament form without authentication', function (): void {
    Filament::setCurrentPanel(null);

    $this->get(route('id-card.public.form'))
        ->assertSuccessful()
        ->assertSeeLivewire(PublicIdCardRequestForm::class);
});

it('submits a sales or courier request with a private uploaded photo', function (string $position, string $businessEntity): void {
    $data = [
        ...$this->idCardFormData(),
        'position'        => $position,
        'business_entity' => $businessEntity,
        'photo'           => UploadedFile::fake()->image('portrait.jpg'),
    ];

    $component = Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm($data)
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertSet('submitted', true);

    $request = IdCardRequest::query()->sole();

    expect($request->full_name)->toBe($data['full_name'])
        ->and($request->shipping_address)->toBe($data['shipping_address'])
        ->and($request->business_entity->value)->toBe($businessEntity)
        ->and($request->position->value)->toBe($position)
        ->and($request->creator_id)->toBeNull()
        ->and($request->phone)->not->toBeEmpty()
        ->and($request->photo)->toStartWith('id-card/photos/');

    Storage::disk('local')->assertExists($request->photo);

    $component->call('submit')->assertSet('submitted', true);

    $this->assertDatabaseCount('id_card_requests', 1);
})->with([
    ['sales', 'smi'],
    ['courier', 'msi'],
    ['sales', 'top'],
]);

it('requires every applicant field', function (): void {
    Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            'full_name'        => null,
            'shipping_address' => null,
            'business_entity'  => null,
            'position'         => null,
            'phone'            => null,
            'photo'            => null,
        ])
        ->call('submit')
        ->assertHasFormErrors([
            'full_name'        => 'required',
            'shipping_address' => 'required',
            'business_entity'  => 'required',
            'position'         => 'required',
            'phone'            => 'required',
            'photo'            => 'required',
        ]);

    $this->assertDatabaseCount('id_card_requests', 0);
});

it('ignores an injected creator id on public submissions', function (): void {
    $user = $this->createIdCardUser();

    Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->image('portrait.jpg'),
        ])
        ->set('data.creator_id', $user->getKey())
        ->call('submit')
        ->assertHasNoErrors();

    expect(IdCardRequest::query()->sole()->creator_id)->toBeNull();
});

it('rejects unsupported business entities positions and phone numbers', function (string $field, string $value): void {
    Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->image('portrait.jpg'),
            $field  => $value,
        ])
        ->call('submit')
        ->assertHasFormErrors([$field]);

    $this->assertDatabaseCount('id_card_requests', 0);
})->with([
    ['business_entity', 'unknown'],
    ['position', 'manager'],
    ['phone', 'not-a-phone'],
]);

it('rejects files that are not supported photos', function (): void {
    Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        ])
        ->call('submit')
        ->assertHasFormErrors(['photo']);

    $this->assertDatabaseCount('id_card_requests', 0);
});

it('rejects photos larger than five megabytes', function (): void {
    Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->image('portrait.jpg')->size(5121),
        ])
        ->call('submit')
        ->assertHasFormErrors(['photo']);

    $this->assertDatabaseCount('id_card_requests', 0);
});

it('rejects an existing stored path supplied instead of a new upload', function (): void {
    $path = 'id-card/photos/another-person.jpg';
    Storage::disk('local')->put($path, UploadedFile::fake()->image('portrait.jpg')->getContent());

    $component = Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => [$path],
        ])
        ->call('submit')
        ->assertHasErrors();

    expect(collect($component->errors()->keys())->contains(
        fn (string $key): bool => str_starts_with($key, 'data.photo.'),
    ))->toBeTrue();

    $this->assertDatabaseCount('id_card_requests', 0);
    Storage::disk('local')->assertExists($path);
});

it('rate limits public submissions before persisting a request', function (): void {
    config()->set('id-card.submissions.max_attempts', 1);
    RateLimiter::hit('id-card:submit:127.0.0.1', 60);

    Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->image('portrait.jpg'),
        ])
        ->call('submit')
        ->assertHasErrors();

    $this->assertDatabaseCount('id_card_requests', 0);
});

it('rejects submissions when the plugin has been uninstalled after the form loaded', function (): void {
    $component = Livewire::test(PublicIdCardRequestForm::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->image('portrait.jpg'),
        ]);

    Plugin::query()->where('name', 'id-card')->update(['is_installed' => false]);
    Package::$plugins = [];

    $component->call('submit')->assertNotFound();

    $this->assertDatabaseCount('id_card_requests', 0);
});
