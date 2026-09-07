<?php

use Cesa\Lead\Http\Controllers\PublicLeadController;
use Cesa\Lead\Livewire\PublicLeadProgressPage;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function (): void {
    Route::redirect('leads', 'lead', 301);

    Route::get('lead', [PublicLeadController::class, 'index'])
        ->name('lead.public.form');

    Route::post('lead/api/submit', [PublicLeadController::class, 'submit'])
        ->name('lead.public.api.submit');

    Route::post('lead/api/check-whatsapp', [PublicLeadController::class, 'checkWhatsApp'])
        ->name('lead.public.api.check-whatsapp');

    Route::get('lead/{lead}', PublicLeadProgressPage::class)
        ->middleware('signed')
        ->name('lead.public.show');
});
