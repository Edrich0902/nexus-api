<?php

use App\Http\Controllers\Api\V1\Kitchen\KitchenController;
use Illuminate\Support\Facades\Route;

Route::prefix('kitchen')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/search', [KitchenController::class, 'search'])
        ->middleware('throttle:kitchen-search');
    Route::get('/browse', [KitchenController::class, 'browse'])
        ->middleware('throttle:kitchen-search');
    Route::get('/filters', [KitchenController::class, 'filters'])
        ->middleware('throttle:kitchen-read');
    Route::get('/random', [KitchenController::class, 'randomMeal'])
        ->middleware('throttle:kitchen-search');
    Route::get('/meals/{mealdbId}', [KitchenController::class, 'showMeal'])
        ->where('mealdbId', '[0-9]+')
        ->middleware('throttle:kitchen-search');

    Route::get('/recipes', [KitchenController::class, 'index'])
        ->middleware('throttle:kitchen-read');
    Route::post('/recipes', [KitchenController::class, 'store'])
        ->middleware('throttle:kitchen-write');
    Route::get('/recipes/{recipe}', [KitchenController::class, 'show'])
        ->whereNumber('recipe')
        ->middleware('throttle:kitchen-read');
    Route::patch('/recipes/{recipe}', [KitchenController::class, 'update'])
        ->whereNumber('recipe')
        ->middleware('throttle:kitchen-write');
    Route::post('/recipes/{recipe}/cooked', [KitchenController::class, 'cooked'])
        ->whereNumber('recipe')
        ->middleware('throttle:kitchen-write');
    Route::delete('/recipes/{recipe}', [KitchenController::class, 'destroy'])
        ->whereNumber('recipe')
        ->middleware('throttle:kitchen-write');
});
