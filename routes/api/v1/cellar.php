<?php

use App\Http\Controllers\Api\V1\Cellar\CellarController;
use Illuminate\Support\Facades\Route;

Route::prefix('cellar')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/quota', [CellarController::class, 'quota'])
        ->middleware('throttle:cellar-read');

    Route::get('/wines', [CellarController::class, 'index'])
        ->middleware('throttle:cellar-read');
    Route::post('/wines', [CellarController::class, 'store'])
        ->middleware('throttle:cellar-write');
    Route::get('/wines/{wine}', [CellarController::class, 'show'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-read');
    Route::patch('/wines/{wine}', [CellarController::class, 'update'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-write');
    Route::delete('/wines/{wine}', [CellarController::class, 'destroy'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-write');

    Route::get('/wines/{wine}/candidates', [CellarController::class, 'candidates'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-match');
    Route::post('/wines/{wine}/match', [CellarController::class, 'confirmMatch'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-match');
    Route::post('/wines/{wine}/no-match', [CellarController::class, 'noMatch'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-write');
    Route::delete('/wines/{wine}/match', [CellarController::class, 'clearMatch'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-write');

    Route::post('/wines/{wine}/tastings', [CellarController::class, 'storeTasting'])
        ->whereNumber('wine')
        ->middleware('throttle:cellar-write');
    Route::patch('/tastings/{tasting}', [CellarController::class, 'updateTasting'])
        ->whereNumber('tasting')
        ->middleware('throttle:cellar-write');
    Route::delete('/tastings/{tasting}', [CellarController::class, 'destroyTasting'])
        ->whereNumber('tasting')
        ->middleware('throttle:cellar-write');
});
