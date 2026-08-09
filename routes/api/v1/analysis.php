<?php

use App\Http\Controllers\Api\V1\Analysis\AnalysisController;
use Illuminate\Support\Facades\Route;

Route::prefix('analysis')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/quota', [AnalysisController::class, 'quota'])
        ->middleware('throttle:drink-analyse');
});
