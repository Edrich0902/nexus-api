<?php

use App\Http\Controllers\Api\V1\Media\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('media')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [MediaController::class, 'index'])
        ->middleware('throttle:media-read');
    Route::post('/', [MediaController::class, 'store'])
        ->middleware('throttle:media-upload');
    Route::post('/from-url', [MediaController::class, 'storeFromUrl'])
        ->middleware('throttle:media-upload');

    Route::get('/usage', [MediaController::class, 'usage'])
        ->middleware('throttle:media-read');
    Route::post('/reconcile', [MediaController::class, 'reconcile'])
        ->middleware('throttle:media-write');

    Route::get('/sources/unsplash', [MediaController::class, 'unsplashSearch'])
        ->middleware('throttle:media-unsplash');

    Route::get('/{media}', [MediaController::class, 'show'])
        ->whereNumber('media')
        ->middleware('throttle:media-read');
    Route::patch('/{media}', [MediaController::class, 'update'])
        ->whereNumber('media')
        ->middleware('throttle:media-write');
    Route::delete('/{media}', [MediaController::class, 'destroy'])
        ->whereNumber('media')
        ->middleware('throttle:media-write');

    Route::post('/{media}/attach', [MediaController::class, 'attach'])
        ->whereNumber('media')
        ->middleware('throttle:media-write');
    Route::delete('/{media}/attach', [MediaController::class, 'detach'])
        ->whereNumber('media')
        ->middleware('throttle:media-write');
});
