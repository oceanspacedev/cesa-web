<?php

use Cesa\FormTransfer\Http\Controllers\PublicAttachmentDownloadController;
use Cesa\FormTransfer\Livewire\PublicCategoryIndex;
use Cesa\FormTransfer\Livewire\PublicTransferApprovalPage;
use Cesa\FormTransfer\Livewire\PublicTransferProgressPage;
use Cesa\FormTransfer\Livewire\PublicTransferRequestForm;
use Cesa\FormTransfer\Livewire\PublicTransferRequestIndex;
use Cesa\FormTransfer\Models\FormTransfer;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function (): void {
    Route::get('afiliasi', fn () => redirect()->route('form-transfer.public.dynamic-index', [
        'publicIndexSlug' => FormTransfer::PUBLIC_INDEX_AFFILIATES,
    ]))
        ->name('form-transfer.public.affiliates');

    Route::get('transfer-requests', fn () => redirect()->route('form-transfer.public.dynamic-index', [
        'publicIndexSlug' => FormTransfer::PUBLIC_INDEX_TRANSFER_REQUESTS,
    ]))
        ->name('form-transfer.public.index');

    Route::get('transfer-requests/approval/{task}', PublicTransferApprovalPage::class)
        ->name('form-transfer.public.approval');

    Route::get('transfer-requests/progress', PublicTransferProgressPage::class)
        ->name('form-transfer.public.progress.lookup');

    Route::get('transfer-requests/progress/{response}', PublicTransferProgressPage::class)
        ->name('form-transfer.public.progress');

    Route::get('transfer-requests/{formTransfer}', PublicTransferRequestForm::class)
        ->name('form-transfer.public.form');

    Route::get('transfer-requests/files/{statusResponseId}/{attachment}', PublicAttachmentDownloadController::class)
        ->name('form-transfer.public.attachments.download');

    Route::get('form', PublicCategoryIndex::class)
        ->name('form-transfer.public.categories');

    Route::get('form/{publicIndexSlug}', PublicTransferRequestIndex::class)
        ->where('publicIndexSlug', '[A-Za-z0-9][A-Za-z0-9_-]*')
        ->name('form-transfer.public.dynamic-index');
});
