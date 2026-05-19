<?php

use App\Http\Controllers\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Middleware\VerifyMetaWebhookSignature;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/webhook', [WhatsAppController::class, 'verify']);
Route::post('/webhook', [WhatsAppController::class, 'handle'])->middleware(VerifyMetaWebhookSignature::class);

// Endpoints para control del Bot
Route::post('/clients/{client}/pause-bot', [\App\Http\Controllers\ClientBotController::class, 'pauseBot']);
Route::post('/clients/{client}/resume-bot', [\App\Http\Controllers\ClientBotController::class, 'resumeBot']);
