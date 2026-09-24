<?php

use Cesa\Waste\Http\Controllers\PublicWasteIndexController;
use Cesa\Waste\Http\Controllers\PublicWasteSubmittedController;
use Cesa\Waste\Http\Controllers\WasteEvidenceController;
use Cesa\Waste\Livewire\PublicWasteApprovalPage;
use Cesa\Waste\Livewire\PublicWasteProgressPage;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Livewire\PublicWasteRevisionPage;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('waste/admin/evidence/{evidence}', [WasteEvidenceController::class, 'admin'])
        ->whereNumber('evidence')
        ->middleware('auth')
        ->name('waste.admin.evidence');

    Route::get('waste', PublicWasteIndexController::class)
        ->middleware('throttle:60,1')
        ->name('waste.public.index');

    Route::get('waste/submitted/{token}/{revision}', PublicWasteSubmittedController::class)
        ->where('token', '[A-Za-z0-9]+')
        ->where('revision', '[A-Za-z0-9]+')
        ->middleware('throttle:60,1')
        ->name('waste.public.submitted');

    Route::get('waste/progress/{token}', PublicWasteProgressPage::class)
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:60,1')
        ->name('waste.public.progress');

    Route::get('waste/manage/{token}', PublicWasteRevisionPage::class)
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:60,1')
        ->name('waste.public.manage');

    Route::get('waste/approval/{token}', PublicWasteApprovalPage::class)
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:60,1')
        ->name('waste.public.approval');

    Route::get('waste/evidence/{evidence}/{token}', WasteEvidenceController::class)
        ->whereNumber('evidence')
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:120,1')
        ->name('waste.public.evidence');

    Route::get('waste/{brand}/{outlet}', PublicWasteReportForm::class)
        ->where('brand', '[A-Za-z0-9_-]+')
        ->where('outlet', '[A-Za-z0-9_-]+')
        ->middleware('throttle:30,1')
        ->name('waste.public.form');
});
