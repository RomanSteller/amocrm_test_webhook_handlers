<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('amocrm')->group(function () {
    Route::get('authorize', [AuthController::class, 'authorize'])->name('amocrm.authorize');
    Route::get('callback', [AuthController::class, 'callback'])->name('amocrm.callback');
    Route::post('webhook', [WebhookController::class, 'handle'])->name('amocrm.webhook');
});

