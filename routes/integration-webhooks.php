<?php

use App\Http\Controllers\IntegrationWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/integrations/{publicId}', IntegrationWebhookController::class)
    ->whereUuid('publicId')->middleware('throttle:120,1')->name('integrations.webhook');
