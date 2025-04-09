<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'super.admin'])->prefix('admin')->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);
    Route::get('logout', [AuthController::class, 'logout']);
    Route::apiResource('companies', CompanyController::class);
});