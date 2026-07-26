<?php

use App\Http\Controllers\Api\V1\Beer\BeerController;
use Illuminate\Support\Facades\Route;

Route::prefix('beer')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/styles', [BeerController::class, 'styles'])
        ->middleware('throttle:beer-read');

    Route::get('/beers', [BeerController::class, 'index'])
        ->middleware('throttle:beer-read');
    Route::post('/beers', [BeerController::class, 'store'])
        ->middleware('throttle:beer-write');
    Route::get('/beers/{beer}', [BeerController::class, 'show'])
        ->whereNumber('beer')
        ->middleware('throttle:beer-read');
    Route::patch('/beers/{beer}', [BeerController::class, 'update'])
        ->whereNumber('beer')
        ->middleware('throttle:beer-write');
    Route::delete('/beers/{beer}', [BeerController::class, 'destroy'])
        ->whereNumber('beer')
        ->middleware('throttle:beer-write');

    Route::get('/breweries', [BeerController::class, 'listBreweries'])
        ->middleware('throttle:beer-read');
    Route::get('/breweries/search', [BeerController::class, 'searchBreweries'])
        ->middleware('throttle:brewery-search');
    Route::post('/breweries/import', [BeerController::class, 'importBrewery'])
        ->middleware('throttle:beer-write');
    Route::post('/breweries', [BeerController::class, 'storeBrewery'])
        ->middleware('throttle:beer-write');
    Route::get('/breweries/{brewery}', [BeerController::class, 'showBrewery'])
        ->whereNumber('brewery')
        ->middleware('throttle:beer-read');
});
