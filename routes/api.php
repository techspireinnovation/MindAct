<?php

use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\AccountHeadController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutoNumberController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MainGroupController;
use App\Http\Controllers\Master\BranchController;
use App\Http\Controllers\MeasureUnitController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductFieldController;
use App\Http\Controllers\ProductFieldValueController;
use App\Http\Controllers\ProductListController;
use App\Http\Controllers\ProductSubCategoryController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SubGroupController;
use App\Http\Controllers\SupplierController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/company/login', [CompanyAdminController::class, 'login']);

Route::middleware(['auth:sanctum', 'super.admin'])->prefix('admin')->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);
    Route::patch('/company-update/{id}', [CompanyController::class, 'updateCompany']);
    Route::put('change-password', [AuthController::class, 'changePassword']);
    Route::put('update', [AuthController::class, 'update']);
    Route::get('logout', [AuthController::class, 'logout']);
    Route::apiResource('companies', CompanyController::class);
    Route::post('/upload', [FileUploadController::class, 'upload']);
    Route::get('/download/{filename}', [FileUploadController::class, 'download']);
});



// company admin operations
Route::middleware(['auth:sanctum', 'company.admin'])->prefix('company')->group(function () {
    Route::post('/upload', [FileUploadController::class, 'upload']);
    Route::get('/download/{filename}', [FileUploadController::class, 'download']);
    Route::get('profile', [CompanyAdminController::class, 'profile']);
    Route::get('logout', [CompanyAdminController::class, 'logout']);
    Route::get('auto-numbers', [AutoNumberController::class, 'getAutoNumbers']);
    Route::put('update', [CompanyController::class, 'update']);
    Route::put('change-password', [CompanyAdminController::class, 'changePassword']);
    Route::apiResource('product-categories', ProductCategoryController::class);
    Route::resource('product-types', ProductTypeController::class);
    Route::resource('branches', BranchController::class);
    Route::resource('measure-units', MeasureUnitController::class);
    Route::resource('products', ProductController::class);
    Route::resource('purchases', PurchaseController::class);
    Route::resource('purchases-returns', PurchaseController::class);
    Route::apiResource('product-sub-categories', ProductSubCategoryController::class);
    Route::apiResource('brands', BrandController::class);
    Route::apiResource('suppliers', App\Http\Controllers\Master\SupplierController::class);
    Route::apiResource('locations', LocationController::class);
    Route::apiResource('main-groups', MainGroupController::class);
    Route::apiResource('sub-groups', SubGroupController::class);
    Route::apiResource('account-groups', AccountGroupController::class);
    Route::apiResource('account-heads', AccountHeadController::class);
    Route::apiResource('product-fields', ProductFieldController::class);
    Route::apiResource('product-field-values', ProductFieldValueController::class);
    Route::apiResource('product-lists', ProductListController::class);
    Route::apiResource('notifications', NotificationController::class)
        ->only(['index', 'update', 'destroy']);
    Route::patch(
        'notifications/{notification}/read',
        [NotificationController::class, 'markAsRead']
    );

});


// forget password
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);