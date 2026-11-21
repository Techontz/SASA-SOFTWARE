<?php

use App\Http\Controllers\Api\V1\Billing\OrganisationBillingController;
use Illuminate\Support\Facades\Route;

// The organisation's own agreed plan, invoices and roles. Registered INSIDE the
// project-scoped group: the organisation is the current project's
// organisation, from TenantContext, and never from the request.
Route::middleware('permission:organisation.billing')->prefix('organisation')->group(function () {
    Route::get('agreement', [OrganisationBillingController::class, 'agreement']);
    Route::patch('agreement/auto-pay', [OrganisationBillingController::class, 'autoPay']);
    Route::get('invoices', [OrganisationBillingController::class, 'invoices']);
    Route::post('invoices/{invoice}/pay', [OrganisationBillingController::class, 'pay'])
        ->where('invoice', '[0-9A-Za-z]{26}')
        ->middleware('throttle:billing');
    Route::get('roles', [OrganisationBillingController::class, 'roles']);
});
