<?php

use App\Events\MessageSent;
use App\Helpers\Helper;
use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\AccountHeadController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutoNumberController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Event\ProductEventController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FixedAssetAccountController;
use App\Http\Controllers\FixedAssetGroupController;
use App\Http\Controllers\JournalVoucherController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MainGroupController;
use App\Http\Controllers\Master\BranchController;
use App\Http\Controllers\MeasureUnitController;
use App\Http\Controllers\NepalLocationPackageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentVoucherController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductFieldController;
use App\Http\Controllers\ProductFieldValueController;
use App\Http\Controllers\ProductionAssembleController;
use App\Http\Controllers\ProductionSettingController;
use App\Http\Controllers\ProductListController;
use App\Http\Controllers\ProductSubCategoryController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReceiptVoucherController;
use App\Http\Controllers\Report\ReportController;
use App\Http\Controllers\SaleAdditionalController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleProductController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\ShrinkingWorkingLossController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockEntryController;
use App\Http\Controllers\StockReconciliationController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SubGroupController;
use App\Http\Controllers\SupplierController;
use Illuminate\Http\Request;

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
    Route::get('/dashboard', [DashboardController::class, 'dashboardStat']);
});




Route::middleware(['auth:sanctum', 'company.admin'])->prefix('company')->group(function () {
    // 

    Route::post('/upload', [FileUploadController::class, 'upload']);
    Route::get('/download/{filename}', [FileUploadController::class, 'download']);
    Route::get('profile', [CompanyAdminController::class, 'profile']);
    Route::get('logout', [CompanyAdminController::class, 'logout']);
    Route::get('auto-numbers', [AutoNumberController::class, 'getAutoNumbers']);
    Route::put('update', [CompanyController::class, 'update']);
    Route::get('sale-products-filter', [SaleController::class, 'getSalesByProduct']);
    Route::get('sale-batch-filter', [SaleController::class, 'getSalesByBatch']);
    Route::get('sale-customer-filter', [SaleController::class, 'getSalesByCustomer']);
    Route::get('get-all-sales-expiry-dates', [SaleController::class, 'getAllExpiryDates']);
    Route::get('get-all-sales-by-expiry-dates', [SaleController::class, 'getSalesByExpiryDate']);
    Route::get('available-products-for-sale', [SaleController::class, 'listAvailableProducts']);
    Route::get('available-products-details-for-sale', [SaleController::class, 'listAvailableProductDetails']);
    Route::get('available-product-details-for-sale-by-name-id', [SaleController::class, 'getAvailableProductByIdOrName']);

    //Sales Returns


    Route::get('sales-returns-product-filter', [SalesReturnController::class, 'getSalesReturnByProduct']);
    Route::get('sales-returns-batch-filter', [SalesReturnController::class, 'getSalesReturnByBatch']);
    Route::get('sales-returns-customer-filter', [SalesReturnController::class, 'getSalesReturnByCustomer']);
    Route::get('get-all-sales-returns-expiry-dates', [SalesReturnController::class, 'getAllExpiryDates']);
    Route::get('get-all-sales-returns-by-expiry-dates', [SalesReturnController::class, 'getSalesReturnByExpiryDate']);
    Route::resource('fixed-asset-accounts', FixedAssetAccountController::class);
    Route::apiResource('sale-additionals', SaleController::class);
    Route::put('change-password', [CompanyAdminController::class, 'changePassword']);
    Route::apiResource('product-categories', ProductCategoryController::class);
    Route::resource('product-types', ProductTypeController::class);
    Route::resource('branches', BranchController::class);
    Route::apiResource('banks', BankController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('journal-vouchers', JournalVoucherController::class);
    Route::resource('customers', CustomerController::class);
    Route::resource('sales', SaleController::class);
    Route::resource('fixed-asset-group', FixedAssetGroupController::class);
    Route::resource('fixed-asset-accounts', FixedAssetGroupController::class);
    Route::resource('sales-returns', SalesReturnController::class);
    Route::resource('sale-products', SaleProductController::class);
    Route::resource('measure-units', MeasureUnitController::class);
    Route::apiResource('products', ProductController::class);

    Route::prefix('reports')->group(function () {
        //Route::middleware(['can:print'])->group(function () {
        Route::get('/stock-register', [ReportController::class, 'stockRegisterDetails']);
        Route::get('/product-list', [ReportController::class, 'productListDetails']);
        Route::get('/product-price-list', [ReportController::class, 'productPriceListDetails']);
        Route::get('/vendor-supplier-list', [ReportController::class, 'vendorSupplierListDetails']);
        Route::get('/stock-ledger-list', [ReportController::class, 'stockLedgerListDetails']);
        //});
    });

    Route::resource('purchases', PurchaseController::class);
    Route::get('product-names-purchases', [PurchaseController::class, 'getProducts']);
    Route::get('generate-purchase-bill-number', [PurchaseController::class, 'generateUniquePurchaseBillNumber']);
    Route::get('product-details-by-names-purchases', [PurchaseController::class, 'getProductDetailsByName']);
    Route::resource('purchase-returns', PurchaseReturnController::class);
    Route::apiResource('product-sub-categories', ProductSubCategoryController::class);
    Route::apiResource('brands', BrandController::class);

    Route::apiResource('suppliers', App\Http\Controllers\Master\SupplierController::class);

    Route::apiResource('stores', StoreController::class);

    Route::apiResource('locations', LocationController::class);
    Route::apiResource('main-groups', MainGroupController::class);
    Route::apiResource('sub-groups', SubGroupController::class);
    Route::apiResource('account-groups', AccountGroupController::class);
    Route::apiResource('account-heads', AccountHeadController::class);
    Route::apiResource('product-fields', ProductFieldController::class);
    Route::apiResource('product-field-values', ProductFieldValueController::class);
    Route::apiResource('sales-returns', SalesReturnController::class);
    Route::apiResource('product-lists', ProductListController::class);

    Route::apiResource('sale-additionals', SaleAdditionalController::class);
    Route::post('broadcast-product-update', [ProductEventController::class, 'index']);
    Route::get('filter-barcode', [ProductController::class, 'filterbyBarcode']);
    Route::get('get-product-names', [ProductController::class, 'getProductNames']);
    Route::get('get-product-detail-by-name', [ProductController::class, 'getProductDetailsByNames']);
    Route::put('purchase-masters-update', [CompanyController::class, 'updatePurchaseMasterKey']);
    Route::get('get-purchase-masters', [CompanyController::class, 'getPurchaseMasterKey']);
    Route::get('get-sales-masters', [CompanyController::class, 'getSalesMasterKey']);
    Route::put('sales-masters-update', [CompanyController::class, 'updateSaleMasterKey']);
    Route::get('get-purchase-bill-numbers', [PurchaseReturnController::class, 'getPurchaseBillNumber']);
    Route::get('get-purchase-by-bill-numbers', [PurchaseReturnController::class, 'getPurchaseByBillNumber']);
    Route::get('get-ref-bill-numbers', [PurchaseReturnController::class, 'getRefBillNumber']);
    Route::get('get-purchase-by-ref-bill-numbers', [PurchaseReturnController::class, 'getPurchaseByRefBillNumber']);
    Route::get('get-purchase-product-names', [PurchaseReturnController::class, 'getProductNames']);
    Route::get('get-provinces', [NepalLocationPackageController::class, 'Province']);
    Route::get('get-provinces-with-districts', [NepalLocationPackageController::class, 'ProvinceWithDistrict']);
    Route::get('generate-product-id', [ProductController::class, 'generateProductID']);
    Route::get('get-provinces-with-districts-municipality', [NepalLocationPackageController::class, 'ProvinceWithDistrictAndMunicipality']);
    Route::get('get-purchase-product-details-by-names', [PurchaseReturnController::class, 'getPurchaseProductDetails']);
    Route::get('get-sales-ref-numbers', [SalesReturnController::class, 'listAvailableRefNumbers']);
    Route::get('get-sales-by-ref-numbers', [SalesReturnController::class, 'getSaleByRefNumber']);
    Route::get('get-sales-invoice-numbers', [SalesReturnController::class, 'listAvailableInvoiceNumbers']);
    Route::get('get-sales-by-invoice-numbers', [SalesReturnController::class, 'getSaleByInvoiceNumber']);
    Route::resource('salesman', SalesmanController::class);
    Route::resource('stock-entries', StockEntryController::class);
    Route::resource('stock-adjustments', StockAdjustmentController::class);
    Route::resource('stock-transfers', StockTransferController::class);
    Route::resource('stock-reconciliation', StockReconciliationController::class);
    Route::resource('production-settings', ProductionSettingController::class);
    Route::resource('production-assembles', ProductionAssembleController::class);
    Route::resource('shrinking-working-loss', ShrinkingWorkingLossController::class);
    Route::resource('receipt-vouchers', ReceiptVoucherController::class);
    Route::resource('payment-vouchers', PaymentVoucherController::class);
    Route::resource('company-staff', StaffController::class);
    Route::post('generate-product-id', [ProductController::class, 'generateProductID']);
    Route::get('generate-unique-invoice-number', [SaleController::class, 'generateUniqueInvoiceNumber']);
    Route::get('get-all-purchase-product-names', [PurchaseReturnController::class, 'getPurchaseProductNames']);
    Route::get('get-all-purchase-product-code', [PurchaseReturnController::class, 'getPurchaseProductUniqueId']);
    Route::get('get-all-purchase-bar-code', [PurchaseReturnController::class, 'getPurchaseProductBarcode']);
    Route::get('get-all-purchase-product-details-by-input', [PurchaseReturnController::class, 'getProductDetailsByInput']);
    Route::get('get-all-customers', [CustomerController::class, 'customerList']);
    Route::get('get-customers-details', [CustomerController::class, 'customerDetails']);
    Route::apiResource('notifications', NotificationController::class)
        ->only(['index', 'update', 'destroy']);
    Route::patch(
        'notifications/{notification}/read',
        [NotificationController::class, 'markAsRead']
    );

});

Route::post('/send-message', function (Request $request) {
    $message = $request->input('message');
    event(new MessageSent($message));
    return response()->json(['status' => 'Message sent']);
});
// forget password
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);