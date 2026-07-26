<?php

use App\Http\Controllers\Api\V1\Library\LibraryController;
use Illuminate\Support\Facades\Route;

Route::prefix('library')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/search', [LibraryController::class, 'search'])
        ->middleware('throttle:library-search');
    Route::get('/pulse', [LibraryController::class, 'pulse'])
        ->middleware('throttle:library-read');

    Route::get('/books', [LibraryController::class, 'index'])
        ->middleware('throttle:library-read');
    Route::post('/books', [LibraryController::class, 'store'])
        ->middleware('throttle:library-write');
    Route::post('/books/from-catalog', [LibraryController::class, 'storeFromCatalog'])
        ->middleware('throttle:library-write');
    Route::get('/books/{book}', [LibraryController::class, 'show'])
        ->whereNumber('book')
        ->middleware('throttle:library-read');
    Route::patch('/books/{book}', [LibraryController::class, 'update'])
        ->whereNumber('book')
        ->middleware('throttle:library-write');
    Route::delete('/books/{book}', [LibraryController::class, 'destroy'])
        ->whereNumber('book')
        ->middleware('throttle:library-write');

    Route::get('/books/{book}/candidates', [LibraryController::class, 'candidates'])
        ->whereNumber('book')
        ->middleware('throttle:library-search');
    Route::post('/books/{book}/match', [LibraryController::class, 'confirmMatch'])
        ->whereNumber('book')
        ->middleware('throttle:library-search');
    Route::post('/books/{book}/no-match', [LibraryController::class, 'noMatch'])
        ->whereNumber('book')
        ->middleware('throttle:library-write');
    Route::delete('/books/{book}/match', [LibraryController::class, 'clearMatch'])
        ->whereNumber('book')
        ->middleware('throttle:library-write');
});
