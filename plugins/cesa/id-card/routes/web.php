<?php

use Cesa\IdCard\Http\Controllers\IdCardPhotoController;
use Cesa\IdCard\Livewire\PublicIdCardRequestForm;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('id-card', PublicIdCardRequestForm::class)
        ->name('id-card.public.form');

    Route::get('id-card/photos/{idCardRequest}', IdCardPhotoController::class)
        ->middleware('auth')
        ->withTrashed()
        ->name('id-card.photos.show');
});
