<?php

use App\Http\Controllers\Api\V1\Spotify\SpotifyConnectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/spotify/callback', [SpotifyConnectionController::class, 'callback'])
    ->middleware('throttle:spotify-oauth-callback');
