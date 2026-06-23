<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/telegram', [TelegramWebhookController::class, 'handle']);

Route::get('/ping', function () {
    return response('pong', 200);
});
