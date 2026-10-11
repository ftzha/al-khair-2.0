<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\CommitteeRegistrationController;

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Email verification routes
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

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('account.active')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Registration routes
Route::middleware(['auth:sanctum', 'account.active'])
    ->prefix('registrations')
    ->group(function () {
        Route::get('/', [RegistrationController::class, 'index']);
        Route::post('/', [RegistrationController::class, 'store']);

        Route::get('/{registration}', [RegistrationController::class, 'show']);

        Route::patch(
            '/{registration}/draft',
            [RegistrationController::class, 'updateDraft']
        );

        Route::post(
            '/{registration}/submit',
            [RegistrationController::class, 'submit']
        );
    });

// Committee registration review routes
Route::middleware(['auth:sanctum', 'account.active'])
    ->prefix('committee/registrations')
    ->group(function () {
        Route::get('/', [CommitteeRegistrationController::class, 'index']);

        Route::get(
            '/{registration}',
            [CommitteeRegistrationController::class, 'show']
        );

        Route::post(
            '/{registration}/review',
            [CommitteeRegistrationController::class, 'review']
        );
    });