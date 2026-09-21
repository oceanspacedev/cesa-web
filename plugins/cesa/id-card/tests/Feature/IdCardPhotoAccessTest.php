<?php

use Cesa\IdCard\Models\IdCardRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Security\Enums\PermissionType;

it('prevents guests and users without view permission from accessing photos', function (): void {
    $request = IdCardRequest::factory()->create();
    $url = route('id-card.photos.show', $request);

    $this->getJson($url)->assertUnauthorized();

    $this->actingAs($this->createIdCardUser())
        ->get($url)
        ->assertForbidden();
});

it('serves stored photos to authorized users including soft deleted requests', function (): void {
    $request = IdCardRequest::factory()->create();
    Storage::disk('local')->put($request->photo, UploadedFile::fake()->image('portrait.jpg')->getContent());

    $this->actingAs($this->createIdCardUser(['view']))
        ->get(route('id-card.photos.show', $request))
        ->assertSuccessful();

    $request->delete();

    $this->get(route('id-card.photos.show', $request))->assertSuccessful();
});

it('denies private photos outside an individual users ownership scope', function (): void {
    $request = IdCardRequest::factory()->create(['creator_id' => null]);

    $this->actingAs($this->createIdCardUser(['view'], PermissionType::INDIVIDUAL))
        ->get(route('id-card.photos.show', $request))
        ->assertForbidden();
});

it('never serves arbitrary files from a forged photo path', function (string $path): void {
    Storage::disk('local')->put('private-document.txt', 'private document');
    $request = IdCardRequest::factory()->create(['photo' => $path]);

    $this->actingAs($this->createIdCardUser(['view']))
        ->get(route('id-card.photos.show', $request))
        ->assertNotFound();
})->with([
    'outside photo directory' => 'private-document.txt',
    'directory traversal'     => 'id-card/photos/../../private-document.txt',
]);

it('returns not found for a missing stored photo', function (): void {
    $request = IdCardRequest::factory()->create();

    $this->actingAs($this->createIdCardUser(['view']))
        ->get(route('id-card.photos.show', $request))
        ->assertNotFound();
});

it('does not remove unrelated private files when permanently deleting a forged photo path', function (): void {
    Storage::disk('local')->put('private-document.txt', 'private document');
    $request = IdCardRequest::factory()->create(['photo' => 'id-card/photos/../../private-document.txt']);

    $request->forceDelete();

    Storage::disk('local')->assertExists('private-document.txt');
});
