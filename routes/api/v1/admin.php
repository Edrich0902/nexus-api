<?php

use App\Http\Controllers\Api\V1\Admin\AdminOpsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/overview', [AdminOpsController::class, 'overview']);
    Route::get('/jobs', [AdminOpsController::class, 'jobs']);
    Route::get('/failed-jobs', [AdminOpsController::class, 'failedJobs']);
    Route::post('/failed-jobs/{uuid}/retry', [AdminOpsController::class, 'retryFailedJob']);
    Route::delete('/failed-jobs/{uuid}', [AdminOpsController::class, 'forgetFailedJob']);
    Route::get('/recent-jobs', [AdminOpsController::class, 'recentJobs']);
});
