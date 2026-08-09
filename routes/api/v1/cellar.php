<?php

use App\Http\Controllers\Api\V1\Cellar\CellarController;
use Illuminate\Support\Facades\Route;

Route::prefix('cellar')->middleware('auth:sanctum')->group(function (): void {
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

    Route::post('/wines/{wine}/analyse', [CellarController::class, 'analyse'])
        ->whereNumber('wine')
        ->middleware('throttle:drink-analyse');

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
