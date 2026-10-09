<?php

use App\Http\Controllers\Api\V1\Hub\HubController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/activity', [HubController::class, 'activity'])
        ->middleware('throttle:hub-read');
    Route::get('/now', [HubController::class, 'now'])
        ->middleware('throttle:hub-read');
    Route::get('/search', [HubController::class, 'search'])
        ->middleware('throttle:global-search');
    Route::get('/palette', [HubController::class, 'palette'])
        ->middleware('throttle:palette');
    Route::post('/palettes', [HubController::class, 'palettes'])
        ->middleware('throttle:palette');
});
