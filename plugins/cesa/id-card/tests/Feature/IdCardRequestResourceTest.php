<?php

use Cesa\IdCard\Filament\Resources\IdCardRequestResource;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\CreateIdCardRequest;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\EditIdCardRequest;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\ListIdCardRequests;
use Cesa\IdCard\Models\IdCardRequest;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\Security\Enums\PermissionType;

it('denies admin list and creation without resource permissions', function (): void {
    $this->actingAs($this->createIdCardUser());

    Livewire::test(ListIdCardRequests::class)->assertForbidden();
    Livewire::test(CreateIdCardRequest::class)->assertForbidden();
});

it('creates and edits an id card request from filament with the authenticated creator', function (): void {
    $user = $this->createIdCardUser(['view_any', 'view', 'create', 'update']);
    $this->actingAs($user);

    Livewire::test(CreateIdCardRequest::class)
        ->fillForm([
            ...$this->idCardFormData(),
            'photo' => UploadedFile::fake()->image('portrait.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = IdCardRequest::query()->sole();

    expect($request->creator_id)->toBe($user->getKey());
    Storage::disk('local')->assertExists($request->photo);

    Livewire::test(EditIdCardRequest::class, ['record' => $request->getRouteKey()])
        ->fillForm([
            'full_name'        => 'Andi Pratama',
            'shipping_address' => 'Jl. Baru No. 7, Jakarta',
            'business_entity'  => 'msi',
            'position'         => 'courier',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('id_card_requests', [
        'id'               => $request->getKey(),
        'full_name'        => 'Andi Pratama',
        'shipping_address' => 'Jl. Baru No. 7, Jakarta',
        'business_entity'  => 'msi',
        'position'         => 'courier',
        'creator_id'       => $user->getKey(),
    ]);
});

it('soft deletes restores and permanently deletes requests through filament actions', function (): void {
    $user = $this->createIdCardUser(['view_any', 'view', 'update', 'delete', 'restore', 'force_delete']);
    $this->actingAs($user);

    $request = IdCardRequest::factory()->create(['creator_id' => $user->getKey()]);
    Storage::disk('local')->put($request->photo, UploadedFile::fake()->image('portrait.jpg')->getContent());

    Livewire::test(EditIdCardRequest::class, ['record' => $request->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertSoftDeleted('id_card_requests', ['id' => $request->getKey()]);
    Storage::disk('local')->assertExists($request->photo);

    Livewire::test(EditIdCardRequest::class, ['record' => $request->getRouteKey()])
        ->callAction(RestoreAction::class);

    $this->assertNotSoftDeleted('id_card_requests', ['id' => $request->getKey()]);

    $request->refresh()->delete();

    Livewire::test(EditIdCardRequest::class, ['record' => $request->getRouteKey()])
        ->callAction(ForceDeleteAction::class);

    $this->assertDatabaseMissing('id_card_requests', ['id' => $request->getKey()]);
    Storage::disk('local')->assertMissing($request->photo);
});

it('searches and filters requests by business entity and position', function (): void {
    $this->actingAs($this->createIdCardUser(['view_any', 'view']));

    $sales = IdCardRequest::factory()->create([
        'full_name'       => 'Target Sales',
        'business_entity' => 'smi',
        'position'        => 'sales',
    ]);
    $courier = IdCardRequest::factory()->create([
        'full_name'       => 'Target Courier',
        'business_entity' => 'top',
        'position'        => 'courier',
    ]);

    Livewire::test(ListIdCardRequests::class)
        ->assertCanSeeTableRecords([$sales, $courier])
        ->searchTable('Target Sales')
        ->assertCanSeeTableRecords([$sales])
        ->assertCanNotSeeTableRecords([$courier])
        ->searchTable('')
        ->filterTable('business_entity', 'top')
        ->assertCanSeeTableRecords([$courier])
        ->assertCanNotSeeTableRecords([$sales])
        ->resetTableFilters()
        ->filterTable('position', 'sales')
        ->assertCanSeeTableRecords([$sales])
        ->assertCanNotSeeTableRecords([$courier]);
});

it('requires each record permission even for users with global scope', function (string $ability, string $permission): void {
    $user = $this->createIdCardUser();
    $request = IdCardRequest::factory()->create(['creator_id' => null]);

    expect(Gate::forUser($user)->allows($ability, $request))->toBeFalse();

    $permittedUser = $this->createIdCardUser([$permission]);

    expect(Gate::forUser($permittedUser)->allows($ability, $request))->toBeTrue();
})->with([
    ['view', 'view'],
    ['update', 'update'],
    ['delete', 'delete'],
    ['restore', 'restore'],
    ['forceDelete', 'force_delete'],
]);

it('restricts individual users to their own requests and excludes public submissions', function (): void {
    $user = $this->createIdCardUser(['view_any', 'view', 'update'], PermissionType::INDIVIDUAL);
    $anotherUser = $this->createIdCardUser();

    $ownRequest = IdCardRequest::factory()->create(['creator_id' => $user->getKey()]);
    $otherRequest = IdCardRequest::factory()->create(['creator_id' => $anotherUser->getKey()]);
    $publicRequest = IdCardRequest::factory()->create(['creator_id' => null]);

    $this->actingAs($user);

    expect(IdCardRequestResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownRequest->getKey()])
        ->and(Gate::allows('view', $ownRequest))->toBeTrue()
        ->and(Gate::allows('update', $ownRequest))->toBeTrue()
        ->and(Gate::allows('view', $otherRequest))->toBeFalse()
        ->and(Gate::allows('update', $otherRequest))->toBeFalse()
        ->and(Gate::allows('view', $publicRequest))->toBeFalse();
});

it('restricts group users without teams to their own requests', function (): void {
    $user = $this->createIdCardUser(['view_any', 'view', 'update'], PermissionType::GROUP);
    $anotherUser = $this->createIdCardUser();
    $ownRequest = IdCardRequest::factory()->create(['creator_id' => $user->getKey()]);
    $otherRequest = IdCardRequest::factory()->create(['creator_id' => $anotherUser->getKey()]);
    $publicRequest = IdCardRequest::factory()->create(['creator_id' => null]);

    $this->actingAs($user);

    expect(IdCardRequestResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownRequest->getKey()])
        ->and(IdCardRequestResource::resolveRecordRouteBinding($ownRequest->getKey())?->getKey())->toBe($ownRequest->getKey())
        ->and(IdCardRequestResource::resolveRecordRouteBinding($otherRequest->getKey()))->toBeNull()
        ->and(IdCardRequestResource::resolveRecordRouteBinding($publicRequest->getKey()))->toBeNull()
        ->and(Gate::allows('view', $ownRequest))->toBeTrue()
        ->and(Gate::allows('view', $otherRequest))->toBeFalse()
        ->and(Gate::allows('view', $publicRequest))->toBeFalse();
});
