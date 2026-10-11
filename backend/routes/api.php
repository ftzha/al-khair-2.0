<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::get('/email/verify/{id}/{hash}', [
    EmailVerificationController::class,
    'verify',
])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [
    EmailVerificationController::class,
    'resend',
])
    ->middleware(['auth:sanctum', 'account.active', 'throttle:6,1'])
    ->name('verification.send');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('account.active')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
    });
});