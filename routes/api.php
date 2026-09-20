<?php

use App\Http\Controllers\WagWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('integrations/wag/webhook', WagWebhookController::class)->name('wag.webhook');
