<?php

use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\AccountHeadController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutoNumberController;
use App\Http\Controllers\AvailableListController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankVoucherController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\VatController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\HoldSaleController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\AreaController;

use App\Http\Controllers\PosStoreController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerateCodeController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\MasterUserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShrinkWorkLossController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkShiftController;
use App\Http\Controllers\NozzleController;
use App\Http\Controllers\StockReceiveController;
use App\Http\Controllers\MeterReadingController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\Event\ProductEventController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FixedAssetAccountController;
use App\Http\Controllers\FixedAssetGroupController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockPurchaseController;
use App\Http\Controllers\StockPurchaseReturnController;
use App\Http\Controllers\JournalVoucherController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MainGroupController;
use App\Http\Controllers\Master\BranchController;
use App\Http\Controllers\MeasureUnitController;
use App\Http\Controllers\MeasureUnitConversionController;
use App\Http\Controllers\NepalLocationPackageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentVoucherController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\StockPurchaseReturnItemWiseController;
use App\Http\Controllers\StockSaleController;
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
use App\Http\Controllers\StockSalesReturnController;
use App\Http\Controllers\StockSalesReturnItemWiseController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SubGroupController;
use App\Http\Controllers\VoucherSummaryController;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Http\Controllers\CompanyDashboard\TransactionSummaryController;
use App\Http\Controllers\CompanyDashboard\SalesPurchaseController;
use App\Http\Controllers\CompanyDashboard\QuickActionController;
use App\Http\Controllers\CompanyDashboard\OverallInformationController;
use App\Http\Controllers\CompanyDashboard\TopSellingProductsController;
use App\Http\Controllers\CompanyDashboard\RecentInvoiceHistoryController;
use App\Http\Controllers\CompanyDashboard\RecentSalesController;
use App\Http\Controllers\CompanyDashboard\LowStockProductsController;
use Illuminate\Support\Facades\Route;





// Public routes

Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login'); // General login
Route::post('/company/login', [CompanyAdminController::class, 'login'])->name('company.login'); // Company admin login
Route::middleware(['auth:sanctum'])->post('/select-admin', [CompanyAdminController::class, 'selectAdmin']);
Route::middleware('auth:sanctum')->post('/company/select-company', [CompanyAdminController::class, 'selectCompany']);
Route::middleware(['auth:sanctum'])
    ->get('/master/company-admin-tree', [CompanyAdminController::class, 'tree']);
Route::get('getUserCompaniesAndBranches/{userId}', [CompanyAdminController::class, 'getUserCompaniesAndBranches']);
Route::get('companies/list-Company-Admins', [CompanyAdminController::class, 'listCompanyAdmins']);
Route::get('/master-users/{masterUserId}/companies', [CompanyAdminController::class, 'getMasterUserCompanies'])->middleware('auth:api');


Route::get('/company/product-types-name/{productType}', [ProductController::class, 'getByProductTypeName'])
    ->middleware('company.access');

Route::middleware(['auth:sanctum', 'super.admin'])->prefix('admin')->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);
    Route::patch('/company-update/{id}', [CompanyController::class, 'updateCompany']);
    Route::put('change-password', [AuthController::class, 'changePassword']);
    Route::put('update', [AuthController::class, 'update']);
    Route::get('logout', [AuthController::class, 'logout']);
    Route::post('/upload', [FileUploadController::class, 'upload']);
    Route::get('/download/{filename}', [FileUploadController::class, 'download']);
    Route::get('/dashboard', [DashboardController::class, 'dashboardStat']);

    Route::get('companies/branch-list', [CompanyController::class, 'companyBranchList'])->name('companies.branch-list');



    Route::get('companies/list', [CompanyController::class, 'companyList'])->name('companies.list');
    Route::get('companies/details', [CompanyController::class, 'companyDetails'])->name('companies.details');
    Route::resource('fiscal-years', FiscalYearController::class);
    Route::get('active-fiscal-years', [FiscalYearController::class, 'activeFiscalYearList']);

    Route::post('assign-fiscal-years', [FiscalYearController::class, 'createAssignFiscalYear']);
    Route::get('assign-fiscal-year-list', [FiscalYearController::class, 'getAssignedFiscalYearCompanyList']);
    Route::get('assign-fiscal-year-details/{companyId}', [FiscalYearController::class, 'getAssignFiscalYearDetails']);
    Route::put('update-assign-fiscal-year/{companyId}', [FiscalYearController::class, 'updateAssignFiscalYear']);
    Route::delete('delete-fiscal-year/{companyId}', [FiscalYearController::class, 'deleteFiscalYear']);

    Route::apiResource('companies', CompanyController::class)->only(['store', 'index', 'show', 'update', 'destroy',]);

});

Route::middleware(['auth:sanctum', 'identify.tenant'])->prefix('company')->group(function () {
    Route::apiResource('product-categories', ProductCategoryController::class);
    Route::apiResource('products', ProductController::class);

    Route::post('products-import-excel', [ProductController::class, 'importExcel']);////


    Route::get('/shrink-work-loss', [ShrinkWorkLossController::class, 'show']);
    Route::put('/shrink-work-loss', [ShrinkWorkLossController::class, 'update']);

    Route::get('/userList', [UserController::class, 'userList']);
    Route::get('/userDetail/{identifier}', [UserController::class, 'userDetail']);


    Route::get('/profile', [CompanyAdminController::class, 'profile'])->name('company.profile');
    Route::post('/logout', [CompanyAdminController::class, 'logout'])->name('company.logout');
    Route::put('/change-password', [CompanyAdminController::class, 'changePassword'])->name('company.change-password');
    Route::get('/generatePurchaseBillNumber', [GenerateCodeController::class, 'generatePurchaseBillNumber']);
    Route::get('/generatePurchaseReturnBillNumber', [GenerateCodeController::class, 'generatePurchaseReturnBillNumber']);
    Route::get('/generateSalesBillNumber', [GenerateCodeController::class, 'generateSalesBillNumber']);
    Route::get('/generateSalesReturnBillNumber', [GenerateCodeController::class, 'generateSalesReturnBillNumber']);
    Route::get('/generateJournalVoucherBillNumber', [GenerateCodeController::class, 'generateJournalVoucherBillNumber']);
    Route::get('/generatePaymentVoucherBillNumber', [GenerateCodeController::class, 'generatePaymentVoucherBillNumber']);
    Route::get('/generateReceiptVoucherBillNumber', [GenerateCodeController::class, 'generateReceiptVoucherBillNumber']);
    Route::get('/generateBankVoucherBillNumber', [GenerateCodeController::class, 'generateBankVoucherBillNumber']);
    Route::get('/generateStockAdjustmentBillNumber', [GenerateCodeController::class, 'generateStockAdjustmentBillNumber']);
    Route::get('/generateStockEntryBillNumber', [GenerateCodeController::class, 'generateStockEntryBillNumber']);
    Route::get('/generatestockreconciliationnumber', [GenerateCodeController::class, 'generateStockReconciliationNumber']);////






    Route::post('/upload', [FileUploadController::class, 'upload']);
    Route::get('/download/{filename}', [FileUploadController::class, 'download']);

    Route::get('auto-numbers', [AutoNumberController::class, 'getAutoNumbers']);
    Route::put('update', [CompanyController::class, 'update']);



    Route::get('get-available-stock-details', [SaleController::class, 'getAvailableProductByIdOrName']);
    Route::get('/get-available-stock-transfer-details/{stockTransferId}', [StockTransferController::class, 'acceptStockTransfer']);
    Route::get('stock-transfer-to-another-branch', [StockTransferController::class, 'acc']);
    Route::get('/sales-filter-by-barcode-id', [SaleController::class, 'filterByBarcode']);////
    Route::get('sale-products-filter', [SaleController::class, 'getSalesByProduct']);
    Route::get('sale-batch-filter', [SaleController::class, 'getSalesByBatch']);
    Route::get('sale-customer-filter', [SaleController::class, 'getSalesByCustomer']);
    Route::get('get-all-sales-expiry-dates', [SaleController::class, 'getAllExpiryDates']);
    Route::get('get-all-sales-by-expiry-dates', [SaleController::class, 'getSalesByExpiryDate']);
    Route::get('available-products-for-sale', [SaleController::class, 'listAvailableProducts']);
    Route::get('available-products-for-sale-for-stocks', [StockAdjustmentController::class, 'listAvailableProductsforStocks']);
    Route::get('available-products-details-for-sale', [SaleController::class, 'listAvailableProductDetails']);
    Route::get('available-product-details-for-sale-by-name-id', [SaleController::class, 'getAvailableProductByIdOrName']);
    Route::get('customer-sales-fiscal-year-details', [SaleController::class, 'customerTotalSalePriceAmount']);

    //Sales Returns

    Route::get('sales-returns-filter-by-barcode-id', [SalesReturnController::class, 'filterByBarcode']);////
    Route::get('sales-returns-product-filter', [SalesReturnController::class, 'getSalesReturnByProduct']);
    Route::get('sales-returns-batch-filter', [SalesReturnController::class, 'getSalesReturnByBatch']);
    Route::get('sales-returns-customer-filter', [SalesReturnController::class, 'getSalesReturnByCustomer']);
    Route::get('get-all-sales-returns-expiry-dates', [SalesReturnController::class, 'getAllExpiryDates']);
    Route::get('get-all-sales-returns-by-expiry-dates', [SalesReturnController::class, 'getSalesReturnByExpiryDate']);
    Route::resource('fixed-asset-accounts', FixedAssetAccountController::class);
    Route::apiResource('sale-additionals', SaleController::class);




    Route::resource('product-types', ProductTypeController::class);


    Route::resource('branches', BranchController::class);
    Route::apiResource('banks', BankController::class);
    Route::get('/bank-vouchers-totals', [BankVoucherController::class, 'getTotals']);////

    Route::apiResource('bank-vouchers', BankVoucherController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::get('journal-vouchers/print', [JournalVoucherController::class, 'print']);
    Route::apiResource('journal-vouchers', JournalVoucherController::class);

    Route::resource('parties', PartyController::class);

    Route::get('sales/get-by-bill-number/{billNumber}', [SaleController::class, 'getItemByBillNumber']);
    Route::resource('sales', SaleController::class);
    Route::resource('hold-sales', HoldSaleController::class);
    Route::post('pos-store', PosStoreController::class);
    Route::resource('fixed-asset-group', FixedAssetGroupController::class);


    //Journal Voucher List for Needed Components


    Route::get('main-group/list', [JournalVoucherController::class, 'mainGroupList']);
    Route::get('sub-group/list', [JournalVoucherController::class, 'subGroupList']);
    Route::get('account-group/list', [JournalVoucherController::class, 'accountGroupList']);
    Route::get('account-head/list', [JournalVoucherController::class, 'accountHeadList']);
    Route::get('sub-group-lists', [SubGroupController::class, 'subGroupList']);
    Route::get('account-group-lists', [AccountGroupController::class, 'accountGroupList']);
    Route::get('account-head-lists', [AccountHeadController::class, 'accountHeadList']);

    Route::get('active-areas-list', [AreaController::class, 'activeAreaList']);
    Route::get('area-details', [AreaController::class, 'areaDetails']);
    Route::get('products-fields', [ProductController::class, 'productFields']);



    Route::get('sales-returns/get-by-bill-number/{billNumber}', [SalesReturnController::class, 'getItemByBillNumber']);

    Route::resource('sales-returns', SalesReturnController::class);
    Route::resource('sale-products', SaleProductController::class);
    Route::resource('measure-units', MeasureUnitController::class);
    Route::resource('measure-unit-conversions', MeasureUnitConversionController::class);
    Route::get('measure-unit-conversions-active-list', [MeasureUnitConversionController::class, 'activeMeasureUnitConversionList']);
    Route::resource('measure-unit-conversions', MeasureUnitConversionController::class);
    Route::apiResource('products', ProductController::class);
    Route::post('/products-import', [ProductController::class, 'import'])->name('products.import');

    Route::prefix('reports')->group(function () {
        //Route::middleware(['can:print'])->group(function () {
        Route::get('/stock-register', [ReportController::class, 'stockRegisterDetails']);
        Route::get('/product-list', [ReportController::class, 'productListDetails']);
        Route::get('/product-price-list', [ReportController::class, 'productPriceListDetails']);
        Route::get('/vendor-supplier-list', [ReportController::class, 'vendorSupplierListDetails']);
        Route::get('/stock-ledger-list', [ReportController::class, 'stockLedgerListDetails']);
        Route::get('/cbms-vat-return-list', [ReportController::class, 'cbmsVatReturnListDetails']);
        Route::get('/vat-return-data-list', [ReportController::class, 'vatReturnDataListDetails']);
        Route::get('/gross-profit-ratio-list', [ReportController::class, 'grossProfitRatioListDetails']);
        Route::get('/gross-margin-list', [ReportController::class, 'grossMarginListDetails']);
        Route::get('/purchase-sales-book-list', [ReportController::class, 'purchaseSalesBookListDetail']);
        //});
    });



    Route::get('/purchases/filter-by-barcode-id', [PurchaseController::class, 'filterbyBarcode']);////
    Route::get('purchases/get-by-bill-number/{billNumber}', [PurchaseController::class, 'getItemByBillNumber']);
    Route::resource('purchases', PurchaseController::class);
    Route::resource('stocks', StockController::class);
    Route::resource('stock-purchases', StockPurchaseController::class);
    Route::resource('stock-purchase-returns', StockPurchaseReturnController::class);
    Route::resource('stock-purchases-itemwise-returns', StockPurchaseReturnItemWiseController::class);
    Route::resource('stock-sales', StockSaleController::class);
    Route::resource('stock-sales-returns', StockSalesReturnController::class);
    Route::resource('stock-sales-itemwise-returns', StockSalesReturnItemWiseController::class);
    Route::get('product-names-purchases', [PurchaseController::class, 'getProducts']);

    Route::get('generate-purchase-bill-number', [PurchaseController::class, 'generateUniquePurchaseBillNumber']);
    Route::get('product-details-by-names-purchases', [PurchaseController::class, 'getProductDetailsByName']);
    Route::get('purchase-returns/get-by-bill-number/{billNumber}', action: [PurchaseReturnController::class, 'getItemByBillNumber']);
    Route::get('show-avaialable-quantity-purhcase-return-bill-wise/{id}', action: [PurchaseReturnController::class, 'showQuantity']);

    Route::get('main-groups-list', [MainGroupController::class, 'mainGroupList']);
    Route::resource('vats', VatController::class);
    Route::get('main-group-lists', [MainGroupController::class, 'mainGroupListDetails']);
    Route::post('sub-groups-update-ranking', [MainGroupController::class, 'draggable']);
    Route::get('sub-groups-of-main', [MainGroupController::class, 'subGroupOfMainGroup']);
    Route::resource('purchase-returns', PurchaseReturnController::class);
    Route::apiResource('product-sub-categories', ProductSubCategoryController::class);
    Route::get('active-brands-list', [BrandController::class, 'activeBrandList'])->name('brands.active');
    Route::apiResource('brands', BrandController::class);
    Route::resource('areas', AreaController::class);
    Route::get('cashes-active-list', [CashController::class, 'activeCashList']);////

    Route::resource('cashes', CashController::class);
    Route::get('available-products-list', [AvailableListController::class, 'productListAvaialable']);
    Route::get('available-products-details', [AvailableListController::class, 'productListAvaialableDetails']);
    Route::get('product-details-sku-code', [AvailableListController::class, 'productDetailsProductCodeSku']);
    Route::get('product-list-billwise-purchase-return-list', [AvailableListController::class, 'productListforTransactionBillWisePurchaseReturn']);
    Route::get('product-list-billwise-purchase-return-details', [AvailableListController::class, 'productforTransactionBillWisePurchaseReturnDetails']);
    Route::get('product-list-billwise-sales-return-list', [AvailableListController::class, 'productListforTransactionBillWiseSalesReturn']);
    Route::get('product-list-billwise-sales-return-details', [AvailableListController::class, 'productforTransactionBillWiseSalesReturnDetails']);
    Route::get('product-list-itemwise-sales-return-list', [AvailableListController::class, 'productListforTransactionItemWiseSalesReturn']);
    Route::get('product-list-itemwise-sales-return-details', [AvailableListController::class, 'productListforTransactionItemWiseSalesReturnDetails']);
    Route::apiResource('suppliers', App\Http\Controllers\Master\SupplierController::class);
    Route::get('active-stores-list', [StoreController::class, 'activeStoreList']);
    Route::apiResource('stores', StoreController::class);
    Route::get('locations-active-list', [LocationController::class, 'activeLocations']);////
    Route::apiResource('locations', LocationController::class);
    Route::apiResource('main-groups', MainGroupController::class);
    Route::apiResource('sub-groups', SubGroupController::class);
    Route::apiResource('account-groups', AccountGroupController::class);
    Route::apiResource('account-heads', AccountHeadController::class);
    Route::apiResource('product-fields', ProductFieldController::class);
    Route::apiResource('product-field-values', ProductFieldValueController::class);
    Route::apiResource('sales-returns', SalesReturnController::class);
    Route::get('product-lists/names', [ProductListController::class, 'productNames']);////
    Route::apiResource('product-lists', ProductListController::class);

    Route::apiResource('sale-additionals', SaleAdditionalController::class);
    Route::post('broadcast-product-update', [ProductEventController::class, 'index']);
    Route::get('filter-barcode', [ProductController::class, 'filterbyBarcode']);
    Route::get('get-product-names', [ProductController::class, 'getProductNames']);
    Route::get('get-products-by-name', [ProductController::class, 'getProductsByName']);
    Route::get('get-product-detail-by-name', [ProductController::class, 'getProductDetailsByNames']);
    Route::put('purchase-masters-update', [CompanyController::class, 'updatePurchaseMasterKey']);
    Route::get('get-purchase-masters', [CompanyController::class, 'getPurchaseMasterKey']);
    Route::get('get-sales-masters', [CompanyController::class, 'getSalesMasterKey']);
    Route::put('sales-masters-update', [CompanyController::class, 'updateSaleMasterKey']);
    Route::get('/purchase-return/filter-by-barcode-id', [PurchaseReturnController::class, 'filterByBarcode']);////

    Route::get('get-purchase-bill-numbers', [GenerateCodeController::class, 'generatePurchaseBillNumbers']);
    Route::get('get-sales-bill-numbers', [GenerateCodeController::class, 'generateSalesBillNumbers']);
    Route::get('get-sales-return-bill-numbers', [GenerateCodeController::class, 'generateSalesReturnBillNumbers']);
    Route::get('get-purchase-return-bill-numbers', [GenerateCodeController::class, 'generatePurchaseReturnBillNumbers']);
    Route::get('get-opening-stock-bill-numbers', [GenerateCodeController::class, 'generateOpeningStockBillNumbers']);
    Route::get('get-purchase-by-bill-numbers', [PurchaseReturnController::class, 'getPurchaseByBillNumber']);
    Route::get('get-ref-bill-numbers', [PurchaseController::class, 'getRefBillNumber']);
    Route::get('get-purchase-by-ref-bill-numbers', [PurchaseReturnController::class, 'getPurchaseByRefBillNumber']);
    Route::get('get-purchase-product-names', [PurchaseReturnController::class, 'getProductNames']);
    Route::get('get-provinces', [NepalLocationPackageController::class, 'Province']);
    Route::get('get-provinces-with-districts', [NepalLocationPackageController::class, 'ProvinceWithDistrict']);
    Route::get('generate-product-id', [GenerateCodeController::class, 'generateProductID']);
    Route::get('get-provinces-with-districts-municipality', [NepalLocationPackageController::class, 'ProvinceWithDistrictAndMunicipality']);
    Route::get('get-purchase-product-details-by-names', [PurchaseReturnController::class, 'getPurchaseProductDetails']);
    Route::get('get-sales-ref-numbers', [SalesReturnController::class, 'listAvailableRefNumbers']);
    Route::get('get-sales-by-ref-numbers', [SalesReturnController::class, 'getSaleByRefNumber']);
    Route::get('get-sales-invoice-numbers', [SalesReturnController::class, 'listAvailableInvoiceNumbers']);
    Route::get('get-sales-by-invoice-numbers', [SalesReturnController::class, 'getSaleByInvoiceNumber']);
    Route::resource('salesman', SalesmanController::class);
    Route::apiResource('stock-entries', StockEntryController::class);
    Route::get('get-available-stock', ([SaleController::class, 'listAvailableProducts']));
    // Route::get('get-available-stock-details', ([StockTransferController::class, 'getProductDetails']));
    Route::put('/stock-entries-update/{id}', [StockEntryController::class, 'update']);
    Route::get('stock-entries-details', [StockEntryController::class, 'show']);
    Route::resource('stock-adjustments', StockAdjustmentController::class);
    Route::resource('stock-transfers', StockTransferController::class);
    Route::resource('stock-receives', StockReceiveController::class);
    Route::resource('stock-reconciliation', StockReconciliationController::class);
    Route::get('products-barcode-uniqueid', [ProductionSettingController::class, 'filterByBarcodeOrUniqueId']);

    Route::resource('production-settings', ProductionSettingController::class);
    Route::resource('production-assembles', ProductionAssembleController::class);
    Route::get('production-settings-list', [ProductionAssembleController::class, 'getProdFmeatheructionSettingList']);
    Route::get('production-settings-details', [ProductionAssembleController::class, 'getProductionSettingDetail']);
    Route::get('filter-barcode', [ProductionAssembleController::class, 'filterByBarcode']);///

    Route::resource('shrinking-working-loss', ShrinkingWorkingLossController::class);
    Route::get('purchase-products-shrinking-working-loss', [ShrinkingWorkingLossController::class, 'getProductDetailsforShrinkingWorkingLoss']);
    Route::get('nozzles-active-list', [NozzleController::class, 'activeNozzles']);////
    Route::resource('receipt-vouchers', ReceiptVoucherController::class);
    Route::resource('payment-vouchers', PaymentVoucherController::class);
    Route::resource('voucher-summary', VoucherSummaryController::class);
    Route::get('voucher-ledger', [VoucherSummaryController::class, 'ledgerList']);
    Route::resource('company-staff', StaffController::class);
    Route::get('active-work-shifts', [WorkShiftController::class, 'activeWorkShiftList']);
    Route::resource('work-shifts', WorkShiftController::class);
    Route::resource('nozzles', NozzleController::class);


    Route::get('products-active-list', [ProductController::class, 'activeProducts']);////

    Route::post('generate-product-id', [GenerateCodeController::class, 'generateProductID']);
    Route::get('generate-unique-invoice-number', [SaleController::class, 'generateUniqueInvoiceNumber']);
    Route::get('get-all-purchase-product-names', [PurchaseReturnController::class, 'getPurchaseProductNames']);
    Route::get('get-all-purchase-product-code', [PurchaseReturnController::class, 'getPurchaseProductUniqueId']);
    Route::get('get-all-purchase-bar-code', [PurchaseReturnController::class, 'getPurchaseProductBarcode']);
    Route::get('get-all-purchase-product-details-by-input', [PurchaseReturnController::class, 'getProductDetailsByInput']);
    Route::post('store-purchase-return-by-item', [PurchaseReturnController::class, 'storePurchaseReturnByInput']);
    Route::put('/update-purchase-return-by-item/{id}', [PurchaseReturnController::class, 'updatePurchaseReturnByInput']);

    Route::get('get-all-sale-product-names', [SalesReturnController::class, 'getSaleProductNames']);
    Route::get('get-available-sale-product-details-for-return', [SalesReturnController::class, 'getAvailableProductsForSalesReturn']);
    Route::post('sales-return-itemwise', [SalesReturnController::class, 'storeItemWise']);
    Route::put('/sales-return-update-itemwise/{id}', [SalesReturnController::class, 'updateItemWise']);

    //List and Detailss
    Route::get('change-test', [SaleController::class, 'changeDate']);
    Route::get('active-party-list', [PartyController::class, 'activePartyList']);
    Route::get('search-parties', [PartyController::class, 'searchPartyList']);
    Route::get('get-party-details', [PartyController::class, 'partyDetails']);

    Route::get('get-area-list', [AreaController::class, 'categoryList']);
    Route::get('get-area-details', [AreaController::class, 'categoryDetails']);

    Route::get('product-categories-list', [ProductCategoryController::class, 'categoryList']);
    Route::get('categories-active-list', [ProductCategoryController::class, 'activeCategoryList']);
    Route::get('product-categories-details', [ProductCategoryController::class, 'categoryDetails']);
    Route::get('/product-types/getById/{id}', [ProductTypeController::class, 'getById']);


    Route::get('product-type-list', [ProductTypeController::class, 'productTypeList']);
    Route::get('/product-types/getById/{id}', [ProductTypeController::class, 'getById']);


    Route::get('active-product-types-list', [ProductTypeController::class, 'activeProductTypeList']);////

    Route::get('product-type-details', [ProductTypeController::class, 'productTypeDetails']);

    Route::get('active-branch-list', [BranchController::class, 'activeBranchList']);
    Route::get('branch-details', [BranchController::class, 'branchDetails']);
    Route::get('branches-active-list', [BranchController::class, 'activeBranchList']);



    Route::get('salesmen-active-list', [SalesmanController::class, 'activesalesmenList']);  ////
    Route::get('salesmen-details', [SalesmanController::class, 'salesmenDetails']);

    Route::get('measure-units-active-list', [MeasureUnitController::class, 'activeUnitList']);////
    Route::get('active-unit-list', [MeasureUnitController::class, 'activeUnitList']);
    Route::get('unit-details', [MeasureUnitController::class, 'unitDetails']);

    Route::get('sub-category-list', [ProductSubCategoryController::class, 'subCategoryList']);
    Route::get('sub-categories-active-list', [ProductSubCategoryController::class, 'activeSubCategoryList']);////
    Route::get('sub-category-details', [ProductSubCategoryController::class, 'subCategoryDetails']);

    Route::get('brand-list', [BrandController::class, 'brandList']);
    Route::get('brand-details', [BrandController::class, 'brandDetails']);

    Route::get('store-list', [StoreController::class, 'storeList']);
    Route::get('store-details', [StoreController::class, 'storeDetails']);


    Route::get('supplier-list', [SupplierController::class, 'supplierList']);
    Route::get('supplier-details', [SupplierController::class, 'supplierDetails']);

    Route::get('active-location-list', [LocationController::class, 'activeLocationList']);
    Route::get('location-details', [LocationController::class, 'locationDetails']);


    Route::get('main-group-list', [MainGroupController::class, 'mgroupList']);
    Route::get('main-group-details', [MainGroupController::class, 'mGroupDetails']);


    Route::get('sub-group-list', [SubGroupController::class, 'subGroupList']);
    Route::get('sub-group-details', [SubGroupController::class, 'subGroupDetails']);

    Route::get('account-group-list', [AccountGroupController::class, 'accountGroupList']);
    Route::get('account-group-details', [AccountGroupController::class, 'accountGroupDetails']);


    Route::get('account-head-list', [AccountHeadController::class, 'accountHeadList']);
    Route::get('account-head-details', [AccountHeadController::class, 'accountHeadDetails']);

    Route::get('product-field-list', [ProductFieldController::class, 'productFieldList']);
    Route::get('product-field-details', [ProductFieldController::class, 'productFieldDetails']);


    Route::get('product-field-value-list', [ProductFieldValueController::class, 'productFieldValueList']);
    Route::get('product-field-value-details', [ProductFieldValueController::class, 'productFieldValueDetails']);
    Route::get('product-field-active', [ProductFieldController::class, 'activeProductFields']);

    Route::get('active-product-list', [ProductController::class, 'activeProductList']);
    Route::get('product-details', [ProductController::class, 'productDetails']);


    Route::get('/banks-active-list', [BankController::class, 'activeBanks']);////

    Route::get('active-banks-lists', [BankController::class, 'activeBankList']);
    Route::get('bank-details', [BankController::class, 'bankDetails']);

    Route::resource('meter-readings', MeterReadingController::class);
    Route::get('meter-readings-last-closing', [MeterReadingController::class, 'getLastClosingReading']);///

    Route::get('project-list', [ProjectController::class, 'projectList']);
    Route::get('project-details', [ProjectController::class, 'projectDetails']);

    Route::get('fixed-asset-group-list', [FixedAssetGroupController::class, 'fixedAssetGroupList']);
    Route::get('fixed-asset-group-details', [FixedAssetGroupController::class, 'fixedAssetGroupDetails']);

    Route::get('fixed-asset-account-list', [FixedAssetAccountController::class, 'fixedAssetAccountList']);
    Route::get('fixed-asset-account-details', [FixedAssetAccountController::class, 'fixedAssetAccountDetails']);

    Route::get('download-file/{filename}', [DownloadController::class, 'download']);

    Route::apiResource('notifications', NotificationController::class)
        ->only(['index', 'update', 'destroy']);
    Route::patch(
        'notifications/{notification}/read',
        [NotificationController::class, 'markAsRead']
    );
});

Route::middleware(['auth:sanctum', SuperAdminMiddleware::class])->group(function () {
    Route::apiResource('master-users', MasterUserController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::get('master-users/{masterUser}/companies-with-branches', [MasterUserController::class, 'companiesWithBranches']);

});

Route::middleware(['auth:sanctum'])->prefix('company')->group(function () {
    // User management routes (company_admin only, assuming company.admin middleware enforces this)
    Route::middleware(['company.admin'])->group(function () {
        Route::post('/users', [UserController::class, 'store'])->name('company.users.store');
        Route::get('/users', [UserController::class, 'index'])->name('company.users.index');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('company.users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('company.users.destroy');




    });

    // Role management routes (company_admin only, assuming company.admin middleware enforces this)
    Route::middleware(['company.admin'])->prefix('role')->group(function () {
        Route::post('/store', [RoleController::class, 'store'])->name('company.role.store');
        Route::get('/', [RoleController::class, 'index'])->name('company.role.index');
        Route::put('/{id}', [RoleController::class, 'update'])->name('company.role.update');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->name('company.role.destroy');
        Route::get('/roles-with-permission', [RoleController::class, 'Roleswithpermission'])->name('company.role.Roleswithpermission');
        Route::patch('/{id}/toggle-active', [RoleController::class, 'toggleActiveStatus'])->name('company.role.toggle-active');
        Route::get('/getById/{id}', [RoleController::class, 'getById'])->name('company.role.getById');

    });





    Route::middleware(['company.access'])->group(function () {


    });
});

// forget password
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);



