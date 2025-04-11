<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductTypeController;


// super admin auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// company auth
Route::post('/company/login', [CompanyAdminController::class, 'login']);


// super admin operations
Route::middleware(['auth:sanctum', 'super.admin'])->prefix('admin')->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);
    Route::get('logout', [AuthController::class, 'logout']);
    Route::apiResource('companies', CompanyController::class);

});
Route::post('/upload', [FileUploadController::class, 'upload']);
Route::get('/download/{filename}', [FileUploadController::class, 'download']);



// company admin operations
Route::middleware(['auth:sanctum', 'company.admin'])->prefix('company')->group(function () {
    Route::get('profile', [CompanyAdminController::class, 'profile']);
    Route::apiResource('product-categories', ProductCategoryController::class);
    Route::resource('product-types', ProductTypeController::class);

});


