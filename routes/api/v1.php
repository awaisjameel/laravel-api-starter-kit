<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Routes for API version 1.
|
*/

// Public routes with auth rate limiter (5/min - brute force protection)
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('api.v1.register');
    Route::post('login', [AuthController::class, 'login'])->name('api.v1.login');
});

// Protected routes with authenticated rate limiter (120/min)
Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');
});

// Admin routes (RBAC enforced via 'admin' middleware)
Route::prefix('admin')->middleware(['auth:sanctum', 'throttle:authenticated', 'admin'])->group(function (): void {
    Route::apiResource('users', AdminUserController::class)->names([
        'index' => 'api.v1.admin.users.index',
        'store' => 'api.v1.admin.users.store',
        'show' => 'api.v1.admin.users.show',
        'update' => 'api.v1.admin.users.update',
        'destroy' => 'api.v1.admin.users.destroy',
    ]);
});
