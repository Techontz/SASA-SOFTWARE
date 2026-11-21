<?php

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
});
