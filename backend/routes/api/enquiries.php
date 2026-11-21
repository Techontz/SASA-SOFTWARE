<?php

use App\Http\Controllers\Api\V1\Demo\AdminDemoController;
use App\Http\Controllers\Api\V1\Enquiry\AdminEnquiryController;
use App\Http\Controllers\Api\V1\Enquiry\PublicEnquiryController;
use App\Http\Controllers\Api\V1\PublicContactController;
use Illuminate\Support\Facades\Route;

// Website enquiries ("Register interest", "Book a demo"). Registered inside the
// /api/v1 prefix but OUTSIDE the project-scoped group: they are platform level.

// --- Public: no account, rate-limited by IP --------------------------------
Route::prefix('public')->withoutMiddleware('throttle:api')->group(function () {
    Route::post('enquiries', [PublicEnquiryController::class, 'store'])->middleware('throttle:enquiries');
});

// How to reach the SASA team. Public, and under the generic `api` limiter.
Route::get('public/contact', PublicContactController::class);

// --- Administration: enquiry.manage, no project context ----------------------
Route::middleware(['auth:sanctum', 'permission:enquiry.manage'])->prefix('admin')->group(function () {
    Route::get('enquiries', [AdminEnquiryController::class, 'index']);
    Route::patch('enquiries/{enquiry:public_id}', [AdminEnquiryController::class, 'update']);

    // Demo workspaces: one action gives the company behind an enquiry its own
    // organisation, project, sample data and an invitation.
    Route::post('enquiries/{enquiry:public_id}/demo', [AdminDemoController::class, 'store']);
    Route::get('demos', [AdminDemoController::class, 'index']);
    Route::post('demos/{demo:public_id}/extend', [AdminDemoController::class, 'extend']);
    Route::post('demos/{demo:public_id}/end', [AdminDemoController::class, 'end']);
    Route::post('demos/{demo:public_id}/resend-invite', [AdminDemoController::class, 'resendInvite']);
});
