<?php

use App\Http\Controllers\Api\V1\Spotify\SpotifyBrowseController;
use App\Http\Controllers\Api\V1\Spotify\SpotifyConnectionController;
use App\Http\Controllers\Api\V1\Spotify\SpotifyLibraryController;
use App\Http\Controllers\Api\V1\Spotify\SpotifyListeningController;
use App\Http\Controllers\Api\V1\Spotify\SpotifyPlayerController;
use App\Http\Controllers\Api\V1\Spotify\SpotifyPlaylistController;
use App\Http\Controllers\Api\V1\Spotify\SpotifySearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('spotify')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/connect', [SpotifyConnectionController::class, 'connect']);
        Route::get('/status', [SpotifyConnectionController::class, 'status']);
        Route::post('/disconnect', [SpotifyConnectionController::class, 'disconnect']);
        Route::post('/sync', [SpotifyConnectionController::class, 'sync'])
            ->middleware('throttle:spotify-sync');

        Route::middleware('throttle:spotify-player')->group(function (): void {
            Route::get('/player', [SpotifyPlayerController::class, 'show']);
            Route::get('/player/devices', [SpotifyPlayerController::class, 'devices']);
            Route::put('/player', [SpotifyPlayerController::class, 'transfer']);
            Route::put('/player/play', [SpotifyPlayerController::class, 'play']);
            Route::put('/player/pause', [SpotifyPlayerController::class, 'pause']);
            Route::post('/player/next', [SpotifyPlayerController::class, 'next']);
            Route::post('/player/previous', [SpotifyPlayerController::class, 'previous']);
            Route::put('/player/seek', [SpotifyPlayerController::class, 'seek']);
            Route::put('/player/volume', [SpotifyPlayerController::class, 'volume']);
            Route::put('/player/shuffle', [SpotifyPlayerController::class, 'shuffle']);
            Route::put('/player/repeat', [SpotifyPlayerController::class, 'repeat']);
            Route::get('/player/queue', [SpotifyPlayerController::class, 'queue']);
            Route::post('/player/queue', [SpotifyPlayerController::class, 'addQueueItem']);
        });

        Route::get('/search', SpotifySearchController::class)
            ->middleware('throttle:spotify-search');

        Route::middleware('throttle:spotify-catalog')->group(function (): void {
            Route::get('/artists/{artistId}', [SpotifyBrowseController::class, 'artist']);
            Route::get('/artists/{artistId}/top-tracks', [SpotifyBrowseController::class, 'artistTopTracks']);
            Route::get('/artists/{artistId}/albums', [SpotifyBrowseController::class, 'artistAlbums']);
            Route::get('/albums/{albumId}', [SpotifyBrowseController::class, 'album']);
        });

        Route::middleware('throttle:spotify-library')->group(function (): void {
            Route::put('/library', [SpotifyLibraryController::class, 'save']);
            Route::delete('/library', [SpotifyLibraryController::class, 'remove']);
            Route::get('/library/contains', [SpotifyLibraryController::class, 'contains']);
            Route::get('/library/tracks', [SpotifyLibraryController::class, 'tracks']);
            Route::get('/library/albums', [SpotifyLibraryController::class, 'albums']);
            Route::get('/library/artists', [SpotifyLibraryController::class, 'artists']);
        });

        Route::get('/playlists', [SpotifyPlaylistController::class, 'index']);
        Route::get('/playlists/containing', [SpotifyPlaylistController::class, 'containing']);
        Route::post('/playlists', [SpotifyPlaylistController::class, 'store']);
        Route::get('/playlists/{playlistId}', [SpotifyPlaylistController::class, 'show']);
        Route::put('/playlists/{playlistId}', [SpotifyPlaylistController::class, 'update']);
        Route::delete('/playlists/{playlistId}', [SpotifyPlaylistController::class, 'destroy']);
        Route::post('/playlists/{playlistId}/items', [SpotifyPlaylistController::class, 'addItems']);
        Route::delete('/playlists/{playlistId}/items', [SpotifyPlaylistController::class, 'removeItems']);
        Route::put('/playlists/{playlistId}/items', [SpotifyPlaylistController::class, 'replaceItems']);

        Route::get('/recently-played', [SpotifyListeningController::class, 'recentlyPlayed']);
        Route::get('/top/{type}', [SpotifyListeningController::class, 'top']);
        Route::get('/taste', [SpotifyListeningController::class, 'taste']);
        Route::get('/suggestions', [SpotifyListeningController::class, 'suggestions']);

        Route::middleware('throttle:spotify-listening')->group(function (): void {
            Route::post('/listening/heartbeat', [SpotifyListeningController::class, 'heartbeat']);
            Route::get('/listening/profile', [SpotifyListeningController::class, 'listeningProfile']);
            Route::get('/listening/settings', [SpotifyListeningController::class, 'listeningSettings']);
            Route::put('/listening/settings', [SpotifyListeningController::class, 'updateListeningSettings']);
            Route::get('/tracks/{spotifyId}/features', [SpotifyListeningController::class, 'trackFeatures']);
            Route::get('/recommendations/similar', [SpotifyListeningController::class, 'similarRecommendations'])
                ->middleware('throttle:spotify-recommendations');
        });
    });
});
