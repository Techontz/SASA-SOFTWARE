<?php

use App\Http\Controllers\Api\V1\Billing\AdminPlanController;
use App\Http\Controllers\Api\V1\Billing\AdminSubscriptionController;
use App\Http\Controllers\Api\V1\Billing\PublicPaymentController;
use App\Http\Controllers\Api\V1\Billing\PublicPlanController;
use App\Http\Controllers\Api\V1\Billing\PublicSubscriptionController;
use App\Http\Controllers\Api\V1\Billing\SnippeWebhookController;
use Illuminate\Support\Facades\Route;

// Subscriptions and payments. Registered inside the /api/v1 prefix but OUTSIDE
// the project-scoped group: plans and subscriptions are platform level.

// --- Public: no account, rate-limited by IP --------------------------------
// The generic `api` limiter (40 a minute for guests) is replaced here by
// limiters sized for the journey: a phone prompt is polled every few seconds.
Route::prefix('public')->withoutMiddleware('throttle:api')->group(function () {
    Route::middleware('throttle:billing-poll')->group(function () {
        Route::get('plans', [PublicPlanController::class, 'index']);
        Route::get('subscriptions/{subscription:public_id}', [PublicSubscriptionController::class, 'show']);
        Route::get('payments/{payment:public_id}', [PublicPaymentController::class, 'show']);
    });

    Route::middleware('throttle:billing')->group(function () {
        Route::post('subscriptions', [PublicSubscriptionController::class, 'store']);
        Route::post('subscriptions/{subscription:public_id}/pay', [PublicSubscriptionController::class, 'pay']);
        Route::post('payments/{payment:public_id}/sandbox-complete', [PublicPaymentController::class, 'sandboxComplete']);
    });

    // Signature-verified, not session-authenticated.
    Route::post('payments/snippe/webhook', SnippeWebhookController::class)->middleware('throttle:billing-webhook');
});

// --- Administration: billing.manage, no project context ----------------------
Route::middleware(['auth:sanctum', 'permission:billing.manage'])->prefix('admin')->group(function () {
    Route::get('plans', [AdminPlanController::class, 'index']);
    Route::post('plans', [AdminPlanController::class, 'store']);
    Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->whereNumber('plan');
    Route::delete('plans/{plan}', [AdminPlanController::class, 'destroy'])->whereNumber('plan');
    Route::post('plans/{plan}/restore', [AdminPlanController::class, 'restore'])->whereNumber('plan');

    Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
    Route::get('subscriptions/{subscription:public_id}', [AdminSubscriptionController::class, 'show']);
    Route::patch('subscriptions/{subscription:public_id}', [AdminSubscriptionController::class, 'update']);
});
