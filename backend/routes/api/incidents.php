<?php

use App\Http\Controllers\Api\V1\IncidentController;
use Illuminate\Support\Facades\Route;

// Routes for this module are registered inside the authenticated, project-scoped
// group in routes/api.php. Paths here are relative to /api/v1.

// --- Incident notification ---------------------------------------------------
Route::prefix('incidents')->group(function () {
    Route::get('/', [IncidentController::class, 'index']);
    Route::post('/', [IncidentController::class, 'store']);
    Route::get('summary', [IncidentController::class, 'summary']);
    Route::get('options', [IncidentController::class, 'options']);
    Route::put('settings', [IncidentController::class, 'updateSettings']);
    Route::get('export', [IncidentController::class, 'export']);
    Route::get('{incident}', [IncidentController::class, 'show'])->whereNumber('incident');
    Route::patch('{incident}', [IncidentController::class, 'update'])->whereNumber('incident');
    Route::post('{incident}/status', [IncidentController::class, 'changeStatus'])->whereNumber('incident');
    Route::delete('{incident}', [IncidentController::class, 'archive'])->whereNumber('incident');
});
