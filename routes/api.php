<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\DocumentController;

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

        // Document routes nested under bundles
        Route::prefix('/{bundle}/documents')->group(function () {
            // Get file tree for the editor
            Route::get('/', [DocumentController::class, 'index']);
            
            // Upload new files (accepts multiple files)
            Route::post('/upload', [DocumentController::class, 'upload']);
            
            // Create folder
            Route::post('/', [DocumentController::class, 'store']);
            
            // Reorder items (drag & drop)
            Route::post('/reorder', [DocumentController::class, 'reorder']);
        });
    });

    // Document-specific routes (need document ID directly)
    Route::prefix('/documents')->group(function () {
        // Stream/download file
        Route::get('/{document}/stream', [DocumentController::class, 'stream'])
            ->name('documents.stream');
        
        // Rename file/folder
        Route::patch('/{document}/rename', [DocumentController::class, 'rename']);
        
        // Delete file/folder
        Route::delete('/{document}', [DocumentController::class, 'destroy']);
    });
});