<?php

use App\Http\Controllers\Api\V1\FoodDrink\FoodDrinkController;
use Illuminate\Support\Facades\Route;

Route::prefix('food-drink')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/dashboard', [FoodDrinkController::class, 'dashboard'])
        ->middleware('throttle:food-drink-read');
    Route::get('/pairings', [FoodDrinkController::class, 'pairings'])
        ->middleware('throttle:food-drink-read');
    Route::post('/pairings', [FoodDrinkController::class, 'storePairing'])
        ->middleware('throttle:cellar-write');
    Route::delete('/pairings/{pairing}', [FoodDrinkController::class, 'destroyPairing'])
        ->whereNumber('pairing')
        ->middleware('throttle:cellar-write');
    Route::get('/suggestions', [FoodDrinkController::class, 'suggestions'])
        ->middleware('throttle:food-drink-read');
});
