<?php

use App\Http\Controllers\Api\V1\Sports\SportsController;
use Illuminate\Support\Facades\Route;

Route::prefix('sports')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/status', [SportsController::class, 'status']);
    Route::post('/sync', [SportsController::class, 'sync'])
        ->middleware('throttle:sports-sync');
    Route::get('/home', [SportsController::class, 'home'])
        ->middleware('throttle:sports-read');

    Route::get('/events/{id}', [SportsController::class, 'event'])
        ->whereNumber('id')
        ->middleware('throttle:sports-read');

    Route::get('/{sport}', [SportsController::class, 'sport'])
        ->where('sport', 'football|tennis|rugby|golf|darts|field-hockey')
        ->middleware('throttle:sports-read');

    Route::get('/{sport}/events', [SportsController::class, 'events'])
        ->where('sport', 'football|tennis|rugby|golf|darts|field-hockey')
        ->middleware('throttle:sports-read');
});
