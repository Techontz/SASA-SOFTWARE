<?php

use App\Http\Controllers\Api\V1\Pap\PapConsultationController;
use App\Http\Controllers\Api\V1\Pap\PapController;
use App\Http\Controllers\Api\V1\Pap\PapRecordController;
use Illuminate\Support\Facades\Route;

// Routes for this module are registered inside the authenticated, project-scoped
// group in routes/api.php. Paths here are relative to /api/v1.

// --- PAP Master Register · Part L consultations (project level) -----------
Route::prefix('pap-consultations')->group(function () {
    Route::get('/', [PapConsultationController::class, 'index']);
    Route::post('/', [PapConsultationController::class, 'store']);
    Route::get('{consultation}', [PapConsultationController::class, 'show'])->whereNumber('consultation');
    Route::patch('{consultation}', [PapConsultationController::class, 'update'])->whereNumber('consultation');
    Route::delete('{consultation}', [PapConsultationController::class, 'archive'])->whereNumber('consultation');
});

// --- PAP Master Register · Part A profile, B–K and M per PAP ----------------
Route::prefix('paps')->group(function () {
    $sections = 'household-surveys|land|assets|crops|valuations|entitlements|payments|relocations|livelihoods|documents';

    Route::get('/', [PapController::class, 'index']);
    Route::post('/', [PapController::class, 'store']);
    Route::get('options', [PapController::class, 'options']);
    Route::get('summary', [PapController::class, 'summary']);
    Route::get('export', [PapController::class, 'export']);
    Route::get('{pap}', [PapController::class, 'show'])->whereNumber('pap');
    Route::patch('{pap}', [PapController::class, 'update'])->whereNumber('pap');
    Route::delete('{pap}', [PapController::class, 'archive'])->whereNumber('pap');
    Route::get('{pap}/grievances', [PapController::class, 'grievances'])->whereNumber('pap');

    Route::get('{pap}/{section}', [PapRecordController::class, 'index'])->whereNumber('pap')->where('section', $sections);
    Route::post('{pap}/{section}', [PapRecordController::class, 'store'])->whereNumber('pap')->where('section', $sections);
    Route::patch('{pap}/{section}/{record}', [PapRecordController::class, 'update'])->whereNumber(['pap', 'record'])->where('section', $sections);
    Route::delete('{pap}/{section}/{record}', [PapRecordController::class, 'archive'])->whereNumber(['pap', 'record'])->where('section', $sections);
});
