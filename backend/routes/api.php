<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\CommitteePaymentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\CommitteeMembershipController;
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
            '/{registration}/identity/reveal',
            [CommitteeRegistrationController::class, 'revealIdentity']
        )->middleware('throttle:6,1');

        Route::post(
            '/{registration}/review',
            [CommitteeRegistrationController::class, 'review']
        );
    });
Route::middleware(['auth:sanctum', 'account.active'])
    ->prefix('invoices')
    ->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/{invoice}', [InvoiceController::class, 'show']);
    });

Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::get('/invoices/{invoice}/payments', [PaymentController::class, 'index']);
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('throttle:30,1');
    Route::get('/committee/payments', [CommitteePaymentController::class, 'index']);
    Route::get('/committee/payments/{payment}', [CommitteePaymentController::class, 'show']);
    Route::post('/committee/payments/{payment}/review', [CommitteePaymentController::class, 'review'])->middleware('throttle:10,1');
    Route::get('/committee/payment-invoices', [CommitteePaymentController::class, 'invoices']);
    Route::post('/committee/payment-invoices/{invoice}/cash', [CommitteePaymentController::class, 'recordCash'])->middleware('throttle:10,1');
});

Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::get('/membership', [MembershipController::class, 'show']);
    Route::post('/membership/renewal-invoice', [MembershipController::class, 'renewal'])->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', 'account.active'])->prefix('committee/memberships')->group(function () {
    Route::get('/', [CommitteeMembershipController::class, 'index']);
    Route::get('/{membership}', [CommitteeMembershipController::class, 'show']);
    Route::post('/{membership}/close', [CommitteeMembershipController::class, 'close'])->middleware('throttle:6,1');
});
