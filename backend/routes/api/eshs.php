<?php

use App\Http\Controllers\Api\V1\EshsAnomalyController;
use Illuminate\Support\Facades\Route;

// Routes for this module are registered inside the authenticated, project-scoped
// group in routes/api.php. Paths here are relative to /api/v1.

// --- ESHS contractor assurance -------------------------------------------
Route::prefix('eshs')->group(function () {
    Route::get('/', [EshsAnomalyController::class, 'index']);
    Route::post('/', [EshsAnomalyController::class, 'store']);
    Route::get('summary', [EshsAnomalyController::class, 'summary']);
    Route::get('options', [EshsAnomalyController::class, 'options']);
    Route::get('export', [EshsAnomalyController::class, 'export']);
    Route::get('settings', [EshsAnomalyController::class, 'settings']);
    Route::put('settings', [EshsAnomalyController::class, 'updateSettings']);
    Route::get('{anomaly}', [EshsAnomalyController::class, 'show'])->whereNumber('anomaly');
    Route::patch('{anomaly}', [EshsAnomalyController::class, 'update'])->whereNumber('anomaly');
    Route::post('{anomaly}/progress', [EshsAnomalyController::class, 'progress'])->whereNumber('anomaly');
    Route::post('{anomaly}/submit', [EshsAnomalyController::class, 'submit'])->whereNumber('anomaly');
    Route::post('{anomaly}/close', [EshsAnomalyController::class, 'close'])->whereNumber('anomaly');
    Route::post('{anomaly}/return', [EshsAnomalyController::class, 'returnToContractor'])->whereNumber('anomaly');
    Route::post('{anomaly}/archive', [EshsAnomalyController::class, 'archive'])->whereNumber('anomaly');
});
