<?php

use App\Http\Controllers\Api\V1\Spirits\SpiritController;
use Illuminate\Support\Facades\Route;

Route::prefix('spirits')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/spirits', [SpiritController::class, 'index'])
        ->middleware('throttle:spirits-read');
    Route::post('/spirits', [SpiritController::class, 'store'])
        ->middleware('throttle:spirits-write');
    Route::get('/spirits/{spirit}', [SpiritController::class, 'show'])
        ->whereNumber('spirit')
        ->middleware('throttle:spirits-read');
    Route::patch('/spirits/{spirit}', [SpiritController::class, 'update'])
        ->whereNumber('spirit')
        ->middleware('throttle:spirits-write');
    Route::delete('/spirits/{spirit}', [SpiritController::class, 'destroy'])
        ->whereNumber('spirit')
        ->middleware('throttle:spirits-write');
    Route::post('/spirits/{spirit}/analyse', [SpiritController::class, 'analyse'])
        ->whereNumber('spirit')
        ->middleware('throttle:drink-analyse');
});
