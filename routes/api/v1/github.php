<?php

use App\Http\Controllers\Api\V1\Github\GithubConnectionController;
use App\Http\Controllers\Api\V1\Github\GithubMeController;
use App\Http\Controllers\Api\V1\Github\GithubPullRequestController;
use App\Http\Controllers\Api\V1\Github\GithubRepoController;
use Illuminate\Support\Facades\Route;

Route::prefix('github')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/connect', [GithubConnectionController::class, 'connect']);
        Route::get('/status', [GithubConnectionController::class, 'status']);
        Route::post('/disconnect', [GithubConnectionController::class, 'disconnect']);
        Route::post('/sync', [GithubConnectionController::class, 'sync'])
            ->middleware('throttle:github-sync');

        Route::get('/me', [GithubMeController::class, 'show'])
            ->middleware('throttle:github-proxy');

        Route::get('/repos', [GithubRepoController::class, 'index'])
            ->middleware('throttle:github-proxy');
        Route::get('/repos/{owner}/{repo}', [GithubRepoController::class, 'show'])
            ->middleware('throttle:github-proxy');
        Route::get('/repos/{owner}/{repo}/branches', [GithubRepoController::class, 'branches'])
            ->middleware('throttle:github-proxy');
        Route::get('/repos/{owner}/{repo}/commits', [GithubRepoController::class, 'commits'])
            ->middleware('throttle:github-proxy');
        Route::get('/repos/{owner}/{repo}/compare', [GithubRepoController::class, 'compare'])
            ->middleware('throttle:github-proxy');

        Route::get('/pulls', [GithubPullRequestController::class, 'inbox'])
            ->middleware('throttle:github-search');
        Route::get('/repos/{owner}/{repo}/pulls', [GithubPullRequestController::class, 'index'])
            ->middleware('throttle:github-proxy');
        Route::post('/repos/{owner}/{repo}/pulls', [GithubPullRequestController::class, 'store'])
            ->middleware('throttle:github-write');
        Route::get('/repos/{owner}/{repo}/pulls/{number}', [GithubPullRequestController::class, 'show'])
            ->middleware('throttle:github-proxy')
            ->whereNumber('number');
        Route::get('/repos/{owner}/{repo}/pulls/{number}/files', [GithubPullRequestController::class, 'files'])
            ->middleware('throttle:github-proxy')
            ->whereNumber('number');
        Route::put('/repos/{owner}/{repo}/pulls/{number}/merge', [GithubPullRequestController::class, 'merge'])
            ->middleware('throttle:github-write')
            ->whereNumber('number');
    });
});
