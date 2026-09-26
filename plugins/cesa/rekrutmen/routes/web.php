<?php

use Cesa\Rekrutmen\Http\Controllers\JobApplicationAttachmentDownloadController;
use Cesa\Rekrutmen\Http\Controllers\PublicRequestManPowerController;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerForm;
use Cesa\Rekrutmen\Http\Controllers\RekrutmenCommunicationSettingsController;
use Cesa\Rekrutmen\Http\Controllers\RekrutmenSpaController;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerApprovalPage;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerProgressPage;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobApplicationHistory;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RequestManPower;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('man-power', PublicRequestManPowerForm::class)
        ->middleware([
            SetCacheHeaders::using([
                'no_store'        => true,
                'no_cache'        => true,
                'must_revalidate' => true,
                'max_age'         => 0,
                'private'         => true,
            ]),
            'throttle:60,1',
        ])
        ->name('rekrutmen.public.request-man-power.form');

    Route::post('man-power/api/submit', [PublicRequestManPowerController::class, 'submit'])
        ->middleware('throttle:60,1')
        ->name('rekrutmen.public.request-man-power.api.submit');

    Route::get('man-power/progress', PublicRequestManPowerProgressPage::class)
        ->name('rekrutmen.public.request-man-power.progress.lookup');

    Route::get('man-power/progress/{response}', PublicRequestManPowerProgressPage::class)
        ->name('rekrutmen.public.request-man-power.progress');

    Route::get('man-power/approval/{token}', PublicRequestManPowerApprovalPage::class)
        ->middleware([
            SetCacheHeaders::using([
                'no_store'        => true,
                'no_cache'        => true,
                'must_revalidate' => true,
                'max_age'         => 0,
                'private'         => true,
            ]),
        ])
        ->name('rekrutmen.public.request-man-power.approval');
});

Route::middleware(['web', 'auth', 'signed'])->group(function () {
    Route::get('rekrutmen/job-applications/{jobApplication}/files/{attachment}', JobApplicationAttachmentDownloadController::class)
        ->name('rekrutmen.job-applications.attachments.download');
});

Route::middleware(['web', 'auth'])->group(function () {
    // API endpoints consumed by Vue SPA
    Route::prefix('rekrutmen/api')->group(function () {
        Route::get('installed-plugins', [RekrutmenSpaController::class, 'getInstalledPluginsApi'])->middleware('can:access_rekrutmen_spa')->name('rekrutmen.api.installed-plugins');
        Route::get('dashboard', [RekrutmenSpaController::class, 'dashboard'])->middleware('can:access_rekrutmen_spa')->name('rekrutmen.api.dashboard');
        Route::get('requests', [RekrutmenSpaController::class, 'getRequests'])->middleware('can:viewAny,'.RequestManPower::class)->name('rekrutmen.api.requests');
        Route::post('requests/{id}/approve', [RekrutmenSpaController::class, 'approveRequest'])->name('rekrutmen.api.requests.approve');
        Route::post('requests/{id}/reject', [RekrutmenSpaController::class, 'rejectRequest'])->name('rekrutmen.api.requests.reject');
        Route::post('requests/{id}/hold', [RekrutmenSpaController::class, 'holdRequest'])->name('rekrutmen.api.requests.hold');

        Route::get('job-postings', [RekrutmenSpaController::class, 'getJobPostings'])->middleware('can:viewAny,'.JobPosting::class)->name('rekrutmen.api.job-postings');
        Route::post('job-postings', [RekrutmenSpaController::class, 'storeJobPosting'])->middleware('can:create,'.JobPosting::class)->name('rekrutmen.api.job-postings.store');
        Route::get('companies', [RekrutmenSpaController::class, 'getCompanies'])->middleware('can:access_rekrutmen_spa')->name('rekrutmen.api.companies');
        Route::patch('job-postings/{id}/publish', [RekrutmenSpaController::class, 'togglePublishJobPosting'])->name('rekrutmen.api.job-postings.publish');
        Route::put('job-postings/{id}', [RekrutmenSpaController::class, 'updateJobPosting'])->name('rekrutmen.api.job-postings.update');
        Route::post('job-postings/{id}', [RekrutmenSpaController::class, 'updateJobPosting'])->name('rekrutmen.api.job-postings.update-post');
        Route::delete('job-postings/{id}', [RekrutmenSpaController::class, 'destroyJobPosting'])->name('rekrutmen.api.job-postings.destroy');

        Route::get('applications', [RekrutmenSpaController::class, 'getApplications'])->middleware('can:viewAny,'.JobApplication::class)->name('rekrutmen.api.applications');
        Route::get('applications/ai-status', [RekrutmenSpaController::class, 'aiScreeningStatus'])->name('rekrutmen.api.applications.ai-status');
        Route::get('applications/{id}/cv', [RekrutmenSpaController::class, 'viewCv'])->name('rekrutmen.api.applications.cv');
        Route::post('applications/{id}/upload-cv', [RekrutmenSpaController::class, 'uploadCv'])->name('rekrutmen.api.applications.upload-cv');
        Route::get('applications/{id}/photo', [RekrutmenSpaController::class, 'viewPhoto'])->name('rekrutmen.api.applications.photo');
        Route::patch('applications/{id}/stage', [RekrutmenSpaController::class, 'updateApplicationStage'])->name('rekrutmen.api.applications.stage');
        Route::patch('applications/{id}/status', [RekrutmenSpaController::class, 'updateApplicationStatus'])->name('rekrutmen.api.applications.status');
        Route::post('applications/batch-reject', [RekrutmenSpaController::class, 'batchRejectApplications'])->name('rekrutmen.api.applications.batch-reject');
        Route::post('applications/sync-cvs', [RekrutmenSpaController::class, 'syncCandidateCvsFromStorage'])->name('rekrutmen.api.applications.sync-cvs');
        Route::post('applications/{id}/analyze-ai', [RekrutmenSpaController::class, 'analyzeWithAi'])->name('rekrutmen.api.applications.analyze-ai');
        Route::post('applications/batch-analyze-ai', [RekrutmenSpaController::class, 'batchAnalyzeWithAi'])->name('rekrutmen.api.applications.batch-analyze-ai');
        Route::get('progress-report', [RekrutmenSpaController::class, 'getProgressReport'])->middleware('can:viewAny,'.JobApplicationHistory::class)->name('rekrutmen.api.progress-report');
        Route::get('progress-report/export', [RekrutmenSpaController::class, 'exportProgressReport'])->middleware('can:viewAny,'.JobApplicationHistory::class)->name('rekrutmen.api.progress-report.export');
        Route::get('configurations', [RekrutmenSpaController::class, 'getConfigurations'])->middleware('can:access_rekrutmen_configurations')->name('rekrutmen.api.configurations');
        Route::post('divisions', [RekrutmenSpaController::class, 'storeDivision'])->name('rekrutmen.api.divisions.store');
        Route::put('divisions/{id}', [RekrutmenSpaController::class, 'updateDivision'])->name('rekrutmen.api.divisions.update');
        Route::delete('divisions/{id}', [RekrutmenSpaController::class, 'destroyDivision'])->name('rekrutmen.api.divisions.destroy');
        Route::post('pipelines', [RekrutmenSpaController::class, 'storePipeline'])->name('rekrutmen.api.pipelines.store');
        Route::put('pipelines/{id}', [RekrutmenSpaController::class, 'updatePipeline'])->name('rekrutmen.api.pipelines.update');
        Route::delete('pipelines/{id}', [RekrutmenSpaController::class, 'destroyPipeline'])->name('rekrutmen.api.pipelines.destroy');
        Route::post('stages', [RekrutmenSpaController::class, 'storeStage'])->name('rekrutmen.api.stages.store');
        Route::post('stages/reorder', [RekrutmenSpaController::class, 'reorderStages'])->name('rekrutmen.api.stages.reorder');
        Route::put('stages/{id}', [RekrutmenSpaController::class, 'updateStage'])->name('rekrutmen.api.stages.update');
        Route::delete('stages/{id}', [RekrutmenSpaController::class, 'destroyStage'])->name('rekrutmen.api.stages.destroy');
        Route::get('settings/ai', [RekrutmenSpaController::class, 'getAiSettings'])->middleware('can:manage_rekrutmen_ai')->name('rekrutmen.api.settings.ai');
        Route::post('settings/ai', [RekrutmenSpaController::class, 'saveAiSettings'])->middleware('can:manage_rekrutmen_ai')->name('rekrutmen.api.settings.ai.save');
        Route::post('settings/ai/test', [RekrutmenSpaController::class, 'testAiConnection'])->middleware('can:manage_rekrutmen_ai')->name('rekrutmen.api.settings.ai.test');
        Route::get('settings/mail-templates', [RekrutmenSpaController::class, 'getMailTemplates'])->middleware('can:manage_rekrutmen_mail')->name('rekrutmen.api.settings.mail-templates');
        Route::post('settings/mail-templates', [RekrutmenSpaController::class, 'saveMailTemplates'])->middleware('can:manage_rekrutmen_mail')->name('rekrutmen.api.settings.mail-templates.save');
        Route::get('settings/mail', [RekrutmenCommunicationSettingsController::class, 'getMailSettings'])->middleware('can:manage_rekrutmen_mail')->name('rekrutmen.api.settings.mail');
        Route::put('settings/mail', [RekrutmenCommunicationSettingsController::class, 'saveMailSettings'])->middleware('can:manage_rekrutmen_mail')->name('rekrutmen.api.settings.mail.save');
        Route::post('settings/mail/test', [RekrutmenCommunicationSettingsController::class, 'testMailSettings'])->middleware('can:manage_rekrutmen_mail')->name('rekrutmen.api.settings.mail.test');
        Route::get('whatsapp/senders', [RekrutmenCommunicationSettingsController::class, 'whatsappSenders'])->name('rekrutmen.api.whatsapp.senders');
        Route::get('notifications/{notification}', [RekrutmenSpaController::class, 'notificationProgress'])->whereNumber('notification')->name('rekrutmen.api.notifications.progress');
        Route::put('settings/whatsapp/integration', [RekrutmenCommunicationSettingsController::class, 'saveWagIntegration'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.integration');
        Route::patch('settings/whatsapp/capacity', [RekrutmenCommunicationSettingsController::class, 'saveWagCapacity'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.capacity');
        Route::post('settings/whatsapp/accounts/{account}/logout', [RekrutmenCommunicationSettingsController::class, 'logoutWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.logout');
        Route::get('settings/whatsapp', [RekrutmenCommunicationSettingsController::class, 'getWhatsAppSettings'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp');
        Route::put('settings/whatsapp', [RekrutmenCommunicationSettingsController::class, 'saveWhatsAppSettings'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.save');
        Route::post('settings/whatsapp/accounts/connect', [RekrutmenCommunicationSettingsController::class, 'connectWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.connect');
        Route::put('settings/whatsapp/accounts/{account}', [RekrutmenCommunicationSettingsController::class, 'updateWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.update');
        Route::delete('settings/whatsapp/accounts/{account}', [RekrutmenCommunicationSettingsController::class, 'destroyWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.destroy');
        Route::post('settings/whatsapp/accounts/{account}/default', [RekrutmenCommunicationSettingsController::class, 'makeDefaultWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.default');
        Route::post('settings/whatsapp/accounts/{account}/test', [RekrutmenCommunicationSettingsController::class, 'testWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.test');
        Route::post('settings/whatsapp/accounts/{account}/connect', [RekrutmenCommunicationSettingsController::class, 'reconnectWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.reconnect');
        Route::get('settings/whatsapp/accounts/{account}/session', [RekrutmenCommunicationSettingsController::class, 'sessionWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.session');
        Route::post('settings/whatsapp/accounts/{account}/disconnect', [RekrutmenCommunicationSettingsController::class, 'disconnectWhatsAppAccount'])->middleware('can:manage_rekrutmen_whatsapp')->name('rekrutmen.api.settings.whatsapp.accounts.disconnect');
        Route::post('applications/{id}/send-email', [RekrutmenSpaController::class, 'sendCandidateEmail'])->name('rekrutmen.api.applications.send-email');
        Route::post('applications/{id}/send-notification', [RekrutmenSpaController::class, 'sendCandidateEmail'])->name('rekrutmen.api.applications.send-notification');
        Route::post('applications/bulk-send-notification', [RekrutmenSpaController::class, 'bulkSendCandidateNotification'])->name('rekrutmen.api.applications.bulk-send-notification');
        Route::post('notifications/heartbeat', [RekrutmenSpaController::class, 'heartbeatScheduled'])->name('rekrutmen.api.notifications.heartbeat');
    });

    // Native Admin Panel URLs taken over by Vue SPA:
    Route::get('admin/request-man-powers{any?}', [RekrutmenSpaController::class, 'index'])->where('any', '.*')->middleware('can:viewAny,'.RequestManPower::class);
    Route::get('admin/job-postings{any?}', [RekrutmenSpaController::class, 'index'])->where('any', '.*')->middleware('can:viewAny,'.JobPosting::class);
    Route::get('admin/job-applications{any?}', [RekrutmenSpaController::class, 'index'])->where('any', '.*')->middleware('can:viewAny,'.JobApplication::class);
    Route::get('admin/recruitment-progress{any?}', [RekrutmenSpaController::class, 'index'])->where('any', '.*')->middleware('can:viewAny,'.JobApplicationHistory::class);
    Route::get('admin/configurations{any?}', [RekrutmenSpaController::class, 'index'])->where('any', '.*')->middleware('can:access_rekrutmen_configurations');
    Route::get('admin/rekrutmen{any?}', [RekrutmenSpaController::class, 'index'])->where('any', '.*')->middleware('can:access_rekrutmen_spa');

    // SPA wildcard fallback (excludes api/ paths to avoid conflicts with POST API routes)
    Route::get('rekrutmen/{any?}', [RekrutmenSpaController::class, 'index'])
        ->where('any', '^(?!api/).*')
        ->middleware('can:access_rekrutmen_spa')
        ->name('rekrutmen.spa');
});
