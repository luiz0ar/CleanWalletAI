<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;

Route::post('/webhook/telegram', [TelegramWebhookController::class, 'handle']);