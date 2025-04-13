<?php

use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\AccountHeadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MainGroupController;
use App\Http\Controllers\Master\BranchController;
use App\Http\Controllers\MeasureUnitController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductFieldController;
use App\Http\Controllers\ProductFieldValueController;
use App\Http\Controllers\ProductSubCategoryController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\SubGroupController;


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
    Route::resource('branches', BranchController::class);
    Route::resource('measure-units', MeasureUnitController::class);
    Route::apiResource('product-sub-categories', ProductSubCategoryController::class);
    Route::apiResource('brands', BrandController::class);
    Route::apiResource('locations', LocationController::class);
    Route::apiResource('main-groups', MainGroupController::class);
    Route::apiResource('sub-groups', SubGroupController::class);
    Route::apiResource('account-groups', AccountGroupController::class);
    Route::apiResource('account-heads', AccountHeadController::class);
    Route::apiResource('product-fields', ProductFieldController::class);
    Route::apiResource('product-field-values', ProductFieldValueController::class);
    Route::apiResource('products', ProductController::class);

});


