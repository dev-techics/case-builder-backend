<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Bundle routes
    Route::prefix('/bundles')->group(function () {
        Route::get('/', [BundleController::class, 'index']);
        Route::post('/', [BundleController::class, 'store']);
        Route::get('/{bundle}', [BundleController::class, 'show']);
        Route::put('/{bundle}', [BundleController::class, 'update']);
        Route::delete('/{bundle}', [BundleController::class, 'destroy']);
    });
});