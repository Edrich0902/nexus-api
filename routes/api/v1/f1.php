<?php

use App\Http\Controllers\Api\V1\F1\F1Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('f1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/status', [F1Controller::class, 'status'])
        ->middleware('throttle:f1-read');
    Route::post('/sync', [F1Controller::class, 'sync'])
        ->middleware('throttle:f1-sync');
    Route::get('/home', [F1Controller::class, 'home'])
        ->middleware('throttle:f1-read');
    Route::get('/season', [F1Controller::class, 'season'])
        ->middleware('throttle:f1-read');
    Route::get('/standings', [F1Controller::class, 'standings'])
        ->middleware('throttle:f1-read');

    Route::get('/meetings/{meetingKey}', [F1Controller::class, 'meeting'])
        ->whereNumber('meetingKey')
        ->middleware('throttle:f1-read');

    Route::get('/sessions/{sessionKey}', [F1Controller::class, 'session'])
        ->whereNumber('sessionKey')
        ->middleware('throttle:f1-read');
    Route::get('/sessions/{sessionKey}/analysis', [F1Controller::class, 'analysis'])
        ->whereNumber('sessionKey')
        ->middleware('throttle:f1-read');
    Route::get('/sessions/{sessionKey}/replay', [F1Controller::class, 'replay'])
        ->whereNumber('sessionKey')
        ->middleware('throttle:f1-read');
    Route::get('/sessions/{sessionKey}/replay/status', [F1Controller::class, 'replayStatus'])
        ->whereNumber('sessionKey')
        ->middleware('throttle:f1-read');
    Route::post('/sessions/{sessionKey}/replay/retry', [F1Controller::class, 'replayRetry'])
        ->whereNumber('sessionKey')
        ->middleware('throttle:f1-sync');
});
