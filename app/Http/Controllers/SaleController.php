<?php

namespace App\Http\Controllers;



use Pratiksh\Nepalidate\Services\NepaliDate;

use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use Pratiksh\Nepalidate\Services\EnglishDate;

use NepaliCalendar;
use App\Services\AvailableQuantityService;
use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\StockAdjusted;
use App\Models\StockAdjustedFieldValue;
use App\Models\StockTransferFieldValue;
use App\Models\StockTransfer;
use App\Models\StockTransferDetails;
use App\Models\PurchaseStockProductReturn;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\Sale;
use App\Models\SaleAdditional;
use App\Models\SaleProduct;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


class SaleController extends Controller
{
    public function generateUniqueInvoiceNumber(Request $request): JsonResponse
    {
        // Prefix for the invoice number
        $prefix = 'INV';

        // Calculate fiscal year based on invoice_date or current date
        $date = Carbon::now();

        $fiscal_year_start = Carbon::create($date->year, 7, 16);
        $fiscalYear = $date->lessThan($fiscal_year_start)
            ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
            : $date->year . '-' . substr($date->year + 1, 2, 2);

        // Extract the starting year from fiscal year (e.g., '2025' from '2025-26')
        $year = substr($fiscalYear, 0, 4);

        // Lock the sales_returns table to prevent race conditions
        return DB::transaction(function () use ($prefix, $year, $fiscalYear) {
            // Log the start of invoice number generation
            Log::info('Generating unique invoice number', [
                'prefix' => $prefix,
                'year' => $year,
                'fiscalYear' => $fiscalYear,
                'date' => now()->toDateTimeString()
            ]);

            // Find the latest invoice number in sales_returns (including soft-deleted)
            $latestInvoice = Sale::withTrashed()
                ->where('invoice_number', 'like', "{$prefix}-{$year}-%")
                ->orderBy('invoice_number', 'desc')
                ->first();

            // Extract the sequence number from the latest invoice or start at 0
            $sequence = 0;
            if ($latestInvoice && preg_match("/{$prefix}-{$year}-(\d+)/", $latestInvoice->invoice_number, $matches)) {
                $sequence = (int) $matches[1];
                Log::debug('Found latest invoice number', [
                    'invoice_number' => $latestInvoice->invoice_number,
                    'sequence' => $sequence
                ]);
            } else {
                Log::debug('No existing invoice numbers found for the year', [
                    'year' => $year
                ]);
            }

            // Increment the sequence
            $newSequence = $sequence + 1;

            // Format the new invoice number with leading zeros (6 digits)
            $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);

            // Construct the new invoice number
            $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";

            // Log the generated invoice number
            Log::info('Generated invoice number', [
                'new_invoice_number' => $newInvoiceNumber,
                'new_sequence' => $newSequence
            ]);

            // Double-check uniqueness in sales_returns (including soft-deleted)
            while (Sale::withTrashed()->where('invoice_number', $newInvoiceNumber)->exists()) {
                $newSequence++;
                $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
                $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";
                Log::warning('Invoice number already exists, incrementing sequence', [
                    'new_invoice_number' => $newInvoiceNumber,
                    'new_sequence' => $newSequence
                ]);
            }

            return response()->json(['invoice_number' => $newInvoiceNumber]);
        });
    }



    public function getAvailableProductsForSale($purchaseType, $companyId, $branchId)
    {

        Log::debug('Fetching available products for sale', ['company_id' => $companyId]);

        try {
            DB::enableQueryLog();


            // Pre-fetch measure units for efficiency
            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');


            // Fetch all relevant products
            $products = Product::select(['id', 'name'])
                ->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                        ->orWhereNull('company_id');
                })
                ->whereNull('deleted_at')
                ->get();


            Log::info('Fetched products', ['products' => $products->pluck('name', 'id')]);

            if ($products->isEmpty()) {
                Log::warning('No products found', ['company_id' => $companyId]);
                return collect([]);
            }

            $productIds = $products->pluck('id')->toArray();





            if (strtolower($purchaseType) === 'capital') {
                Log::warning('Purchase type "Capital" is not allowed', [
                    'company_id' => $companyId,
                    'purchase_type' => $purchaseType,
                ]);
                return collect([]);
            }


            // Fetch purchase products
            $purchaseProducts = PurchaseStockProduct::select('purchase_stock_products.*')   // <── essential
                ->whereIn('purchase_stock_products.product_id', $productIds)
                ->where('purchase_stock_products.company_id', $companyId)
                ->whereNull('purchase_stock_products.deleted_at')

                ->where('purchase_stock_products.purchase_type', $purchaseType)

                // eager-load relations exactly as before
                ->with([
                    'purchaseStockProductReturns' => fn($q) => $q
                        ->whereNull('purchase_stock_product_returns.deleted_at')
                        ->where('purchase_stock_product_returns.company_id', $companyId)
                        ->where('purchase_stock_product_returns.branch_id', $branchId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),

                    'saleProducts' => fn($q) => $q
                        ->whereNull('sale_products.deleted_at')
                        ->where('sale_products.company_id', $companyId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q
                                ->whereNull('sales_return_products.deleted_at')
                                ->where('sales_return_products.company_id', $companyId)
                                ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])])
                        ]),

                    'fieldValues' => fn($q) => $q
                        ->whereNull('purchase_stock_product_field_values.deleted_at')
                        ->where('purchase_stock_product_field_values.company_id', $companyId)
                ])
                ->get();


            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', ['company_id' => $companyId, 'product_ids' => $productIds]);
                return collect([]);
            }


            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['sale_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $saleProduct = SaleProduct::find($fv->sale_product_id);
                    return $saleProduct ? $saleProduct->purchase_stock_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $purchaseProducts->flatMap(fn($pp) => $pp->purchaseStockProductReturns->pluck('id')))
                ->where('company_id', $companyId)
                ->where('branch_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['purchase_stock_product_return_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseStockProductReturn::find($fv->purchase_stock_product_return_id);
                    return $returnProduct ? $returnProduct->purchase_stock_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            // Process products
            $results = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $measureUnitsCalc) {
                $productPurchaseProducts = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->id);


                $purchasedPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {

                    return $this->calculatePieces(
                        ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                        $measureUnitsCalc[$pp->measure_unit_id]?->quantity ?? 1
                    );

                });

                $returnPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->purchaseStockProductReturns->reduce(
                        fn($carry, $return) => $carry + $this->calculatePieces(
                            ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                            $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });
                $returnPieces = min($returnPieces, $purchasedPieces);

                $salePieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->saleProducts->reduce(
                        fn($carry, $sale) => $carry + $this->calculatePieces(
                            ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                            $measureUnitsCalc[$sale->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });

                $salesReturnPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                        fn($carry, $return) => $carry + $this->calculatePieces(
                            ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                            $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });

                $availablePieces = $purchasedPieces - $returnPieces - $salePieces + $salesReturnPieces;

                return (object) [
                    'id' => $product->id,
                    'name' => $product->name,
                    'purchased_quantity' => $purchasedPieces,
                    'return_quantity' => $returnPieces,
                    'sale_quantity' => $salePieces,
                    'sales_return_quantity' => $salesReturnPieces,
                    'available_quantity' => max(0, (int) $availablePieces),
                ];
            })->filter(fn($product) => $product->available_quantity > 0)->values();

            Log::debug('Available products query', [
                'sql' => DB::getQueryLog(),
                'results_count' => $results->count(),
                'products' => $results->toArray()
            ]);

            return $results;
            // dd($results);

        } catch (\Exception $e) {
            Log::error('Error fetching available products for sale', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            DB::disableQueryLog();
        }
    }



    public function listAvailableProducts(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'nullable|integer',
                'include_details' => 'nullable|boolean',
                'purchase_type' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $companyId = $request->input('company_id') ?? $request->company_id;
            $branchId = $request->input('branch_id') ?? $request->branch_id;
            $includeDetails = $request->boolean('include_details', false);
            $purchaseType = $request->input('purchase_type', null);

            \Log::info('listAvailableProducts: Processing', [
                'user_id' => auth()->id(),
                'company_id' => $companyId,
                'include_details' => $includeDetails,
                'purchase_type' => $purchaseType
            ]);

            if (!auth()->check()) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            if (!$companyId) {
                return response()->json([
                    'message' => 'No company ID provided or available'
                ], 400);
            }


            $products = $includeDetails
                ? collect($this->getAvailableProductsDetails(null, null, $companyId)['data'], $branchId)
                : $this->getAvailableProductsForSale($purchaseType, $companyId, $branchId);


            return response()->json([
                'message' => 'Available products retrieved successfully',
                'count' => $products->count(),
                'data' => $products
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error listing available products', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to retrieve available products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    public function getAvailableProductByIdOrName(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'nullable|integer|exists:products,id',
                'product_name' => 'nullable|string|max:255',
                'company_id' => 'required|integer',
                'response_unit_id' => 'nullable|integer|exists:measure_units,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productId = $request->input('product_id');
            $productName = trim(strtolower($request->input('product_name')));
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $responseUnitId = $request->input('response_unit_id');

            Log::debug('Input parameters', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'response_unit_id' => $responseUnitId
            ]);

            if (!$productId && !$productName) {
                return response()->json(['error' => 'Either product_id or product_name is required'], 422);
            }

            // Fetch product details
            $products = $this->getAvailableProductsDetails($productId, $productName, $companyId, $branchId, $responseUnitId);

            return response()->json([
                'message' => !empty($products['data']) ? 'Product details retrieved' : 'No matching product found',
                'data' => $products['data'] ?: []
            ], !empty($products['data']) ? 200 : 200);

        } catch (ModelNotFoundException $e) {
            Log::error('Model not found in getAvailableProductByIdOrName', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'No matching product found', 'data' => []], 200);
        } catch (QueryException $e) {
            Log::error('Database query error in getAvailableProductByIdOrName', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Database query error',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getAvailableProductByIdOrName', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }





    public function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null, ?int $branchId = null, ?int $responseUnitId = null): array
    {
        Log::debug('Fetching detailed available products with purchase products', [
            'product_id' => $productId,
            'product_name' => $productName,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'response_unit_id' => $responseUnitId
        ]);

        try {
            DB::enableQueryLog();

            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            Log::debug('Measure units fetched', [
                'company_id' => $companyId,
                'measure_units_count' => $measureUnitsCalc->count(),
                'measure_unit_ids' => $measureUnitsCalc->keys()->toArray()
            ]);

            // Validate response_unit_id (optional)
            if ($responseUnitId && !isset($measureUnitsCalc[$responseUnitId])) {
                Log::warning('Invalid response unit ID', ['response_unit_id' => $responseUnitId]);
                return ['message' => 'Invalid response unit ID', 'data' => []];
            }

            // Fetch products
            $productsQuery = Product::select([
                'products.id as product_id',
                'products.name as product_name',
                'products.product_unique_id as product_code',
                'products.measure_unit_id',
                'measure_units.name as measure_unit_name',
                'measure_units.quantity as measure_unit_quantity',
                'products.is_vatable',
            ])
                ->leftJoin('measure_units', 'products.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('products.deleted_at')
                ->where(function ($query) use ($companyId) {
                    $query->where('products.company_id', $companyId)
                        ->orWhereNull('products.company_id');
                });

            if ($productId) {
                $productsQuery->where('products.id', $productId);
            }

            if ($productName) {
                $productsQuery->where('products.name', $productName);
            }

            $products = $productsQuery->get();

            Log::debug('Products fetched', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'products_count' => $products->count(),
                'product_ids' => $products->pluck('product_id')->toArray(),
                'query_log' => DB::getQueryLog()
            ]);

            if ($products->isEmpty()) {
                Log::warning('No products found', [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            $productIds = $products->pluck('product_id')->toArray();

            $productForUnit = $productId ?? ($productName ? Product::where('name', $productName)->first()->id ?? null : null);

            if (!$productForUnit) {
                Log::warning('No product found for unit calculation', [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'company_id' => $companyId
                ]);
                return ['message' => 'No product found', 'data' => []];
            }

            $retailSalePrice = Product::where('id', $productForUnit)->pluck('retail_sales_price')->first();
            $productSoldPrice = SaleProduct::where('product_id', $productForUnit)
                ->orderByDesc('created_at')
                ->get(['price', 'created_at']);

            $avgPrice = $productSoldPrice->avg('price');
            $minPrice = $productSoldPrice->min('price');
            $latestSoldPrice = $productSoldPrice->first()->price ?? 0;

            Log::debug('Product pricing calculated', [
                'product_id' => $productForUnit,
                'retail_sale_price' => $retailSalePrice,
                'avg_price' => $avgPrice,
                'min_price' => $minPrice,
                'latest_sold_price' => $latestSoldPrice
            ]);

            $getProductForMeasureUnits = Product::with('productLists')
                ->where('id', $productForUnit)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->first();

            $productPrimaryMeasureUnit = ProductList::where('product_id', $productForUnit)
                ->where('is_primary', 1)
                ->pluck('measure_unit_id')
                ->first();

            if (!$productPrimaryMeasureUnit) {
                $productPrimaryMeasureUnit = ProductList::where('product_id', $productForUnit)
                    ->orderBy('created_at', 'asc')
                    ->pluck('measure_unit_id')
                    ->first();
            }

            $primarayMeasureUnitId = MeasureUnit::where('id', $productPrimaryMeasureUnit)->first();
            $primaryMeasureUnitQuantity = $primarayMeasureUnitId->quantity ?? 0;

            Log::debug('Primary measure unit determined', [
                'product_id' => $productForUnit,
                'primary_measure_unit_id' => $productPrimaryMeasureUnit,
                'primary_measure_unit_quantity' => $primaryMeasureUnitQuantity
            ]);

            $allUnitIds = $getProductForMeasureUnits
                ? collect([$getProductForMeasureUnits->measure_unit_id])
                    ->merge($getProductForMeasureUnits->productLists->pluck('measure_unit_id'))
                    ->unique()
                    ->values()
                : collect([]);

            $measureUnitsUsed = MeasureUnit::whereIn('id', $allUnitIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity'])
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });

            Log::debug('Measure units used', [
                'product_id' => $productForUnit,
                'measure_unit_ids' => $allUnitIds->toArray(),
                'measure_units_used' => $measureUnitsUsed->toArray()
            ]);

            $purchaseProducts = PurchaseStockProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with([
                    'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),
                    'saleProducts' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q->whereNull('deleted_at')
                                ->where('company_id', $companyId)
                                ->where('branch_id', $branchId)
                                ->with([
                                    'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                                    'fieldValues' => fn($q) => $q->whereNull('deleted_at')
                                        ->where('company_id', $companyId)
                                        ->where('branch_id', $branchId)
                                        ->select(['sale_return_product_id', 'quantity_index'])
                                ]),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')
                                ->where('company_id', $companyId)
                                ->where('branch_id', $branchId)
                                ->select(['sale_product_id', 'quantity_index'])
                        ]),
                    'fieldValues' => fn($q) => $q->whereNull('purchase_stock_product_field_values.deleted_at')
                        ->where('purchase_stock_product_field_values.company_id', $companyId)
                        ->where('purchase_stock_product_field_values.branch_id', $branchId)
                        ->with([
                            'productField' => fn($q) => $q->select(['id', 'name', 'company_id'])
                                ->where('company_id', $companyId)
                                ->whereNull('deleted_at')
                        ])
                ])
                ->orderBy('created_at', 'asc')
                ->get();

            Log::debug('Purchase stock products fetched', [
                'product_ids' => $productIds,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'purchase_products_count' => $purchaseProducts->count(),
                'purchase_product_ids' => $purchaseProducts->pluck('id')->toArray(),
                'purchase_products' => $purchaseProducts->map(fn($pp) => [
                    'id' => $pp->id,
                    'product_id' => $pp->product_id,
                    'quantity' => $pp->quantity,
                    'free_quantity' => $pp->free_quantity,
                    'measure_unit_id' => $pp->measure_unit_id
                ])->toArray(),
                'query_log' => DB::getQueryLog()
            ]);

            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', [
                    'product_ids' => $productIds,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            $purchaseProductIds = $purchaseProducts->pluck('id')->toArray();

            // ——— FINAL & SIMPLE: Use `quantity` (positive) from stock_adjusteds ———
            $adjustmentSubtractions = StockAdjusted::whereIn('purchase_stock_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('adjusted_type', 'subtract')
                ->whereNull('deleted_at')
                ->get(['purchase_stock_product_id', 'quantity', 'measure_unit_id'])
                ->groupBy('purchase_stock_product_id')
                ->map(fn($items) => $items->sum(
                    fn($adj) =>
                    $adj->quantity * ($measureUnitsCalc[$adj->measure_unit_id]->quantity ?? 1)
                ));

            $adjustmentIndexes = StockAdjustedFieldValue::whereIn('purchase_stock_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select('purchase_stock_product_id', 'quantity_index')
                ->get()
                ->groupBy('purchase_stock_product_id')
                ->map->pluck('quantity_index')->unique()->values();

            Log::debug('Stock adjustments (subtract) loaded', [
                'subtracted_pieces_by_batch' => $adjustmentSubtractions->toArray(),
                'adjustment_indexes_by_batch' => $adjustmentIndexes->toArray(),
            ]);

            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select(['sale_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $saleProduct = SaleProduct::find($fv->sale_product_id);
                    return $saleProduct ? $saleProduct->purchase_stock_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            Log::debug('Sold quantity indexes fetched', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'sold_quantity_indexes' => $soldQuantityIndexes->toArray()
            ]);

            $returnedQuantityIndexes = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $purchaseProducts->flatMap(fn($pp) => $pp->purchaseStockProductReturns->pluck('id')))
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select(['purchase_stock_product_return_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseStockProductReturn::find($fv->purchase_stock_product_return_id);
                    return $returnProduct ? $returnProduct->purchase_stock_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $transferQuantityIndexes = StockTransferFieldValue::whereIn('purchase_stock_product_id', $purchaseProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select(['purchase_stock_product_id', 'quantity_index'])
                ->get()
                ->groupBy('purchase_stock_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            // Log the results for debugging
            Log::debug('Transfer quantity indexes fetched', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'purchase_product_ids' => $purchaseProducts->pluck('id')->toArray(),
                'transfer_quantity_indexes' => $transferQuantityIndexes->toArray()
            ]);

            // Check for missing purchase_stock_product_ids
            $expectedIds = $purchaseProducts->pluck('id')->toArray();
            $returnedIds = array_keys($transferQuantityIndexes->toArray());
            $missingIds = array_diff($expectedIds, $returnedIds);
            if (!empty($missingIds)) {
                Log::warning('Missing quantity indexes for some purchase_stock_product_ids in stock transfers', [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'missing_ids' => $missingIds,
                    'expected_ids' => $expectedIds
                ]);
                // Initialize missing IDs with empty arrays to prevent errors
                foreach ($missingIds as $missingId) {
                    $transferQuantityIndexes[$missingId] = [];
                }
            }

            $saleReturnProductIds = $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))->unique();
            $salesReturnQuantityIndexes = collect();
            if ($saleReturnProductIds->isNotEmpty()) {
                $salesReturnQuantityIndexes = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $saleReturnProductIds)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->select(['sale_return_product_id', 'quantity_index'])
                    ->get()
                    ->groupBy(function ($fv) {
                        $saleReturnProduct = SalesReturnProduct::find($fv->sale_return_product_id);
                        return $saleReturnProduct ? $saleReturnProduct->saleProduct->purchase_stock_product_id : null;
                    })
                    ->map(fn($group) => $group->pluck('quantity_index')->toArray());
            }

            Log::debug('Sales return quantity indexes fetched', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'sale_return_product_ids' => $saleReturnProductIds->toArray(),
                'sales_return_quantity_indexes' => $salesReturnQuantityIndexes->toArray()
            ]);

            // Process results
            $result = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $salesReturnQuantityIndexes, $transferQuantityIndexes, $adjustmentIndexes, $companyId, $branchId, $measureUnitsCalc, $measureUnitsUsed, $latestSoldPrice, $minPrice, $avgPrice, $retailSalePrice, $primaryMeasureUnitQuantity, $primarayMeasureUnitId) {
                $allFieldValues = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->flatMap(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $salesReturnQuantityIndexes, $transferQuantityIndexes, $adjustmentIndexes) {
                        // Only exclude sold indices that weren't returned
                        $netSoldIndexes = array_diff($soldQuantityIndexes[$pp->id] ?? [], $salesReturnQuantityIndexes[$pp->id] ?? []);
                        $excludedIndexes = array_unique(array_merge(
                            $netSoldIndexes,
                            $returnedQuantityIndexes[$pp->id]
                            ?? [],
                            $adjustmentIndexes[$pp->id]
                            ?? [],
                            $transferQuantityIndexes[$pp->id] ?? []
                        ));
                        return $pp->fieldValues->filter(function ($fv) use ($excludedIndexes) {
                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_stock_product_field_value_id' => $fv->id,
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'purchase_product_id' => $fv->purchase_product_id,
                                'stock_product_id' => $fv->stock_product_id,
                                'stock_adjustment_id' => $fv->stock_adjustment_id,
                                'stock_reconciliation_id' => $fv->stock_reconciliation_id,
                                'stock_transfer_id' => $fv->stock_transfer_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField->name ?? null,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values();
                    })->toArray();

                Log::debug('All field values for product', [
                    'product_id' => $product->product_id,
                    'field_values_count' => count($allFieldValues),
                    'field_values' => $allFieldValues
                ]);

                $productPurchaseProducts = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->map(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $salesReturnQuantityIndexes, $adjustmentIndexes, $companyId, $branchId, $measureUnitsCalc) {
                        // Calculate purchased pieces
                        $purchasedPieces = $this->calculatePieces(
                            ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                            measureUnitQuantity: isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1
                        );

                        // Calculate return pieces, capped at purchased pieces
                        $returnPieces = $pp->purchaseStockProductReturns->reduce(
                            fn($carry, $return) => $carry + $this->calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $returnPieces = min($returnPieces, $purchasedPieces);

                        // Calculate sale and sales return pieces
                        $salePieces = $pp->saleProducts->reduce(
                            fn($carry, $sale) => $carry + $this->calculatePieces(
                                ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                                isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $salesReturnPieces = $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                            fn($carry, $return) => $carry + $this->calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );

                        // Calculate available pieces
                        // $pp->adjustment_subtracted_pieces = $adjustmentSubtractions[$pp->id] ?? 0;
                        $availablePieces = $this->calculateAvailablePieces($pp, $companyId, $branchId, $measureUnitsCalc);

                        Log::debug('Purchase stock product quantities calculated', [
                            'purchase_stock_product_id' => $pp->id,
                            'product_id' => $pp->product_id,
                            'purchased_pieces' => $purchasedPieces,
                            'return_pieces' => $returnPieces,
                            'sale_pieces' => $salePieces,
                            'sales_return_pieces' => $salesReturnPieces,
                            'available_pieces' => $availablePieces
                        ]);

                        // Collect field values for this purchase product
                        $netSoldIndexes = array_diff($soldQuantityIndexes[$pp->id] ?? [], $salesReturnQuantityIndexes[$pp->id] ?? []);
                        $excludedIndexes = array_unique(array_merge(
                            $netSoldIndexes,
                            $returnedQuantityIndexes[$pp->id] ?? [],
                            $adjustmentIndexes[$pp->id] ?? [],        
                            $transferQuantityIndexes[$pp->id] ?? []
                        ));

                        Log::debug('Field values before filtering', [
                            'purchase_stock_product_id' => $pp->id,
                            'field_values_count' => $pp->fieldValues->count(),
                            'field_values' => $pp->fieldValues->map(fn($fv) => [
                                'id' => $fv->id,
                                'quantity_index' => $fv->quantity_index,
                                'product_field_id' => $fv->product_field_id,
                                'value' => $fv->value,
                                'product_field_name' => $fv->productField?->name
                            ])->toArray(),
                            'excluded_indexes' => $excludedIndexes
                        ]);

                        $fieldValues = $pp->fieldValues->filter(function ($fv) use ($excludedIndexes) {
                            $isAvailable = !in_array($fv->quantity_index, $excludedIndexes);
                            Log::debug('Field value availability check', [
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'field_value_id' => $fv->id,
                                'quantity_index' => $fv->quantity_index,
                                'product_field_id' => $fv->product_field_id,
                                'value' => $fv->value,
                                'is_available' => $isAvailable
                            ]);
                            return $isAvailable;
                        })->map(function ($fv) {
                            return [
                                'purchase_stock_product_field_value_id' => $fv->id,
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'purchase_product_id' => $fv->purchase_product_id,
                                'stock_product_id' => $fv->stock_product_id,
                                'stock_reconciliation_id' => $fv->stock_reconciliation_id,
                                'stock_transfer_id' => $fv->stock_transfer_id,
                                'stock_adjustment_id' => $fv->stock_adjustment_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField?->name ?? 'Unknown',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values()->toArray();

                        Log::debug('Field values after filtering', [
                            'purchase_stock_product_id' => $pp->id,
                            'field_values_count' => count($fieldValues),
                            'field_values' => $fieldValues
                        ]);

                        return [
                            'purchase_stock_product_id' => $pp->id,
                            'purchase_id' => $pp->purchase_id ?? null,
                            'purchase_bill_number' => $pp->purchase?->purchase_bill_number ?? null,
                            'invoice_date' => $pp->purchase?->invoice_date ?? null,
                            'product_id' => $pp->product_id,
                            'product_name' => $pp->product_name,
                            'product_code' => $pp->product_code,
                            'mfd' => $pp->mfd,
                            'quantity' => $pp->quantity,
                            'free_quantity' => $pp->free_quantity ?? 0,
                            'price' => $pp->price ?? 0,
                            'is_vatable' => (bool) $pp->is_vatable,
                            'measure_unit_id' => $pp->measure_unit_id,
                            'measure_unit_name' => isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->name : null,
                            'measure_unit_quantity' => isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1,
                            'expiry_date' => $pp->expiry_date,
                            'return_quantity' => $returnPieces,
                            'sale_quantity' => $salePieces,
                            'sales_return_quantity' => $salesReturnPieces,
                            'available_quantity' => max($availablePieces, 0),
                            'purchased_quantity' => $purchasedPieces,
                            'field_values' => $fieldValues
                        ];
                    })->values()->toArray();

                // Aggregate totals in pieces
                $purchasedPieces = array_sum(array_map(
                    fn($pp) => $this->calculatePieces(
                        ($pp['quantity'] ?? 0) + ($pp['free_quantity'] ?? 0),
                        $pp['measure_unit_quantity'] ?? 1
                    ),
                    $productPurchaseProducts
                ));
                $returnPieces = array_sum(array_map(
                    fn($pp) => $pp['return_quantity'],
                    $productPurchaseProducts
                ));
                $returnPieces = min($returnPieces, $purchasedPieces);
                $salePieces = array_sum(array_map(
                    fn($pp) => $pp['sale_quantity'],
                    $productPurchaseProducts
                ));
                $salesReturnPieces = array_sum(array_map(
                    fn($pp) => $pp['sales_return_quantity'],
                    $productPurchaseProducts
                ));

                // Use the sum of available pieces from each batch (already includes adjustments!)
                $availablePieces = array_sum(array_map(fn($pp) => $pp['available_quantity'], $productPurchaseProducts));

                Log::debug('Product totals calculated', [
                    'product_id' => $product->product_id,
                    'purchased_pieces' => $purchasedPieces,
                    'return_pieces' => $returnPieces,
                    'sale_pieces' => $salePieces,
                    'sales_return_pieces' => $salesReturnPieces,
                    'available_pieces' => $availablePieces
                ]);

                $salesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->pluck('price');
                $lastSalesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at')
                    ->value('price');

                return [
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'is_vatable' => (bool) $product->is_vatable,
                    'measure_unit_id' => $primarayMeasureUnitId->id ?? null,
                    'measure_unit_quantity' => $primaryMeasureUnitQuantity,
                    'retail_sale_price' => $retailSalePrice ?? 0,
                    'avg_price' => $avgPrice ?? 0,
                    'min_price' => $minPrice ?? 0,
                    'latest_price' => $latestSoldPrice ?? 0,
                    'measure_units_used' => $measureUnitsUsed,
                    'avg_sales_price' => round($salesPrice->avg(), 2) ?: null,
                    'min_sales_price' => round($salesPrice->min(), 2) ?: null,
                    'latest_sales_price' => round($lastSalesPrice, 2) ?: null,
                    'purchased_quantity' => $purchasedPieces,
                    'return_quantity' => $returnPieces,
                    'sale_quantity' => $salePieces,
                    'sales_return_quantity' => $salesReturnPieces,
                    'available_quantity' => max($availablePieces, 0),
                    'expiry_dates' => array_filter(array_unique(array_column($productPurchaseProducts, 'expiry_date'))),
                    'field_values' => $allFieldValues,
                    'purchase_products' => $productPurchaseProducts
                ];
            })->filter()->values()->toArray();

            Log::debug('Final result prepared', [
                'products_count' => count($result),
                'products' => array_map(fn($item) => [
                    'product_id' => $item['product_id'],
                    'available_quantity' => $item['available_quantity'],
                    'purchase_products_count' => count($item['purchase_products'])
                ], $result)
            ]);

            return [
                'message' => 'Product details retrieved',
                'data' => $result
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching detailed available products', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            throw $e;
        } finally {
            DB::disableQueryLog();
        }
    }





    public function changeDate(Request $request)
    {
        $dateString = $request->input('date');

        $carbonDate = Carbon::parse($dateString);
        $currentDateBs = NepaliDate::create($carbonDate)->toBS();
        return $currentDateBs;

    }




    public function safeBsDate(int $year, int $month, int $day): string
    {
        // never start higher than 32
        $day = min($day, 33);

        while ($day > 0) {
            $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);

            try {

                EnglishDate::create($candidate)->toAD();
                return $candidate;
            } catch (\Throwable $e) {
                $day--;
            }
        }

        throw new \RuntimeException("No valid day found for {$year}-{$month}");
    }


    public function customerTotalSalePriceAmount(Request $request)
    {
        try {

            $customer_id = $request->input('customer_id');
            $currentDate = Carbon::now(); // July 24, 2025
            $currentDateBs = NepaliDate::create($currentDate)->toBS();



            [$bsYear, $bsMonth, $bsDay] = array_map('intval', explode('-', $currentDateBs));


            if ($bsMonth >= 4 || ($bsMonth === 4 && $bsDay >= 1)) { // On or after Shrawan 1
                $fiscalBsYearStart = $bsYear;
                $fiscalBsYearEnd = $bsYear + 1;
            } else {
                $fiscalBsYearStart = $bsYear - 1;
                $fiscalBsYearEnd = $bsYear;
            }

            // Define full fiscal year dates
            $fiscalYearStartBs = $fiscalBsYearStart . '-04-01'; // Shrawan 1
            $asarLastDay = $fiscalBsYearEnd;
            $lastFiscalDate = $this->safeBsDate($asarLastDay, 3, 32); // Asar 32 of the fiscal year end

            $saleIds = Sale::where('company_id', $request->company_id)
                ->where('customer_id', $customer_id)
                ->whereNull('deleted_at')
                ->whereBetween('invoice_date_bs', [$fiscalYearStartBs, $lastFiscalDate])
                ->pluck('id');

            $totalSoldPrice = SaleProduct::whereIn('sale_id', $saleIds)
                ->whereNull('deleted_at')
                ->sum('price');

            $totalSoldAmount = SaleProduct::whereIn('sale_id', $saleIds)
                ->whereNull('deleted_at')
                ->sum('amount');
            return response()->json([
                'message' => 'Total sale price and amount retrieved successfully',
                'data' => [
                    'price' => $totalSoldPrice,
                    'amount' => $totalSoldAmount
                ]
            ], 200);


        } catch (ModelNotFoundException $e) {
            Log::error('Data not Found', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Data nor Found !!', 'message' => config('app.debug') ? $e->getMessage() : null], 404);


        } catch (QueryException $e) {
            Log::error('Database query error in customerTotalSalePriceAmount', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Database query error', 'message' => config('app.debug') ? $e->getMessage() : null], 500);

        } catch (\Exception $e) {
            Log::error('Error in customerTotalSalePriceAmount', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }



    public function calculatePieces(string $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity', ['measureUnitQuantity' => $measureUnitQuantity]);
            return 0;
        }

        // Split integer and decimal parts WITHOUT float
        [$integerPart, $decimalPart] = array_pad(explode('.', $quantity), 2, '0');

        $integer = (int) $integerPart;
        $decimalPieces = (int) $decimalPart;

        return ($integer * $measureUnitQuantity) + $decimalPieces;
    }



    public function calculateAvailablePieces($purchaseProduct, int $companyId, int $branchId, $measureUnitsCalc): int
    {
        $purchaseMeasureUnitQuantity = isset($measureUnitsCalc[$purchaseProduct->measure_unit_id]) ? $measureUnitsCalc[$purchaseProduct->measure_unit_id]->quantity : 1;

        Log::debug('Measure unit quantity', [
            'purchase_stock_product_id' => $purchaseProduct->id,
            'measure_unit_id' => $purchaseProduct->measure_unit_id,
            'purchaseMeasureUnitQuantity' => $purchaseMeasureUnitQuantity
        ]);

        if ($purchaseMeasureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity for purchase product', [
                'purchase_stock_product_id' => $purchaseProduct->id,
                'measureUnitQuantity' => $purchaseMeasureUnitQuantity
            ]);
            return 0;
        }

        // Log purchase product data
        Log::debug('Purchase product data', [
            'purchase_stock_product_id' => $purchaseProduct->id,
            'quantity' => $purchaseProduct->quantity ?? 0,
            'free_quantity' => $purchaseProduct->free_quantity ?? 0
        ]);

        // Prioritize field values if they exist
        $fieldValues = $purchaseProduct->fieldValues->whereNull('deleted_at')->groupBy('quantity_index');
        if ($fieldValues->isNotEmpty()) {
            $unavailableIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $companyId, $branchId);
            $availablePieces = $fieldValues->filter(function ($fv, $index) use ($unavailableIndices) {
                return !in_array($index, $unavailableIndices);
            })->count();

            Log::debug('Calculated available pieces via field values', [
                'purchase_stock_product_id' => $purchaseProduct->id,
                'total_field_values' => $fieldValues->count(),
                'unavailable_indices' => $unavailableIndices,
                'available_pieces' => $availablePieces
            ]);

            return max(0, $availablePieces);
        }

        // Fallback to quantity-based calculation
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $purchaseMeasureUnitQuantity);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $purchaseMeasureUnitQuantity);
        $totalPurchasedPieces = $regularPieces + $freePieces;

        $purchaseReturnedPieces = $purchaseProduct->purchaseStockProductReturns->reduce(
            function ($carry, $return) use ($measureUnitsCalc) {
                $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                    $returnMeasureUnitQuantity
                );
            },
            0
        );

        $soldPieces = $purchaseProduct->saleProducts->reduce(
            function ($carry, $sale) use ($measureUnitsCalc) {
                $saleMeasureUnitQuantity = isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                    $saleMeasureUnitQuantity
                );
            },
            0
        );




        $salesReturnedPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($companyId, $measureUnitsCalc) {
            return $sale->saleProductReturns->where('company_id', $companyId)->whereNull('deleted_at');
        })->reduce(
                function ($carry, $return) use ($measureUnitsCalc) {
                    $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                    return $carry + $this->calculatePieces(
                        ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                        $returnMeasureUnitQuantity
                    );
                },
                0
            );


        // SUPER SIMPLE: Get pre-calculated subtracted pieces from attribute
        $adjustedPieces = $purchaseProduct->stockAdjusted()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)

            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $adjust) =>
                $carry
                + $this->calculatePieces($adjust->quantity ?? 0, $adjust->measureUnit->quantity ?? 1)
                + $this->calculatePieces($adjust->free_quantity ?? 0, $adjust->measureUnit->quantity ?? 1)
                ,
                0
            );



        $availablePieces = $totalPurchasedPieces - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces - $adjustedPieces;

        if ($availablePieces < 0) {
            Log::warning('Negative available pieces detected', [
                'purchase_stock_product_id' => $purchaseProduct->id,
                'total_purchased' => $totalPurchasedPieces,
                'purchase_returned' => $purchaseReturnedPieces,
                'sold' => $soldPieces,
                'sales_returned' => $salesReturnedPieces,
                'available' => $availablePieces
            ]);
        }

        Log::debug('Calculated available pieces via quantities', [
            'purchase_stock_product_id' => $purchaseProduct->id,
            'total_purchased' => $totalPurchasedPieces,
            'purchase_returned' => $purchaseReturnedPieces,
            'sold' => $soldPieces,
            'sales_returned' => $salesReturnedPieces,
            'available' => $availablePieces
        ]);

        return max(0, (int) $availablePieces); // Remove floor, cast to int
    }



    public function availablePiecesForSaleUpdate(
        $purchaseProduct,
        float $measureUnitQty,
        int $companyId,
        int $branchId,
        ?int $ignoreSaleId = null
    ): float {

        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQty);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQty);
        $purchasedPieces = $regularPieces + $freePieces;


        $purchaseReturnedPieces = $purchaseProduct->purchaseStockProductReturns()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $ret) =>
                $carry
                + $this->calculatePieces($ret->quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                + $this->calculatePieces($ret->free_quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                ,
                0
            );


        $soldPieces = $purchaseProduct->saleProducts()
            ->where('company_id', $companyId)
            ->when($ignoreSaleId, fn($q, $id) => $q->where('sale_id', '!=', $id))
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $sale) =>
                $carry
                + $this->calculatePieces($sale->quantity ?? 0, $sale->measureUnit->quantity ?? 1)
                + $this->calculatePieces($sale->free_quantity ?? 0, $sale->measureUnit->quantity ?? 1)
                ,
                0
            );


        $adjustedPieces = $purchaseProduct->stockAdjusted()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)

            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $adjust) =>
                $carry
                + $this->calculatePieces($adjust->quantity ?? 0, $adjust->measureUnit->quantity ?? 1)
                + $this->calculatePieces($adjust->free_quantity ?? 0, $adjust->measureUnit->quantity ?? 1)
                ,
                0
            );


        $customerReturnedPieces = SalesReturnProduct::where('product_id', $purchaseProduct->product_id)
            ->whereHas(
                'saleProduct',
                fn($q) =>
                $q->where('purchase_product_id', $purchaseProduct->id)
                    ->where('company_id', $companyId)
            )
            ->when(
                $ignoreSaleId,
                fn($q, $id) =>

                $q->whereHas('saleProduct.sale', fn($sq) => $sq->where('id', '!=', $id))
            )
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $ret) =>
                $carry
                + $this->calculatePieces($ret->quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                + $this->calculatePieces($ret->free_quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                ,
                0
            );

        $available = max(0, $purchasedPieces - $purchaseReturnedPieces - $soldPieces + $customerReturnedPieces - $adjustedPieces);

        Log::debug('Available pieces for sale update', [
            'purchase_stock_product_id' => $purchaseProduct->id,
            'purchased' => $purchasedPieces,
            'purchaseRet' => $purchaseReturnedPieces,
            'sold' => $soldPieces,
            'custReturned' => $customerReturnedPieces,
            'available' => $available,
        ]);

        return $available;
    }



    public function getItemByBillNumber($billNumber): JsonResponse
    {
        try {
            $purchase = Sale::where('invoice_number', $billNumber)->firstOrFail();
            return $this->show($purchase->id);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function index(Request $request): JsonResponse
    {
        $query = Sale::query();

        if ($request->has('keywords')) {
            $query->where('ref_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('customer_name', 'LIKE', '%' . $request->input('keywords') . '%');

        }
        return response()->json($query->paginate(100));
    }



    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'salesman_id' => 'nullable|integer|exists:salesmen,id',
                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.bank_name' => 'nullable|string',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'invoice_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sales')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'freight_charge' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'balance' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'ref_bill_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales', 'ref_number')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'round_off_amount' => 'nullable|numeric|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'vat_amount' => 'nullable|numeric',
                'abvt' => 'nullable|boolean',
                'is_vatable' => 'nullable|boolean',
                'total_amount' => 'nullable|numeric|min:0',
                'sell_entire_batch' => 'nullable|boolean',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'batch_no_sale' => 'nullable|string|max:255',
                'sale_products' => [
                    'required_unless:sell_entire_batch,true',
                    'array',
                    'min:1',
                ],
                'sale_products.*.product_name' => 'required_without:sale_products.*.product_id|string|max:255',
                'sale_products.*.product_id' => 'nullable|integer|exists:products,id',
                'sale_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'sale_products.*.purchase_product_id' => 'nullable',
                'sale_products.*.stock_product_id' => 'nullable',
                'sale_products.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.stock_transfer_id' => 'nullable',
                'sale_products.*.quantity' => 'nullable|string',
                'sale_products.*.free_quantity' => 'nullable|string',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'sale_products.*.batch_no' => 'nullable|string|max:255',
                'sale_products.*.amount' => 'nullable|numeric|min:0',
                'sale_products.*.mfd' => 'nullable|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|string|max:255',
                'sale_products.*.field_values' => 'present|array',
                'sale_products.*.field_values.*' => 'array|min:1',
                'sale_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'sale_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'sale_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'sale_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'sale_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',

                'sale_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
                'sale_additionals.company_id' => 'nullable|integer|exists:companies,id',
                'sale_additionals.sale_id' => 'nullable|string|max:255',
                'sale_additionals.place' => 'nullable|string|max:255',
                'sale_additionals.transport' => 'nullable|string|max:255',
                'sale_additionals.vehicle_number' => 'nullable|string|max:255',
                'sale_additionals.vehicle_name' => 'nullable|string|max:255',
                'sale_additionals.driver_name' => 'nullable|string|max:255',
                'sale_additionals.dispatch_code' => 'required_if:sale_additionals,exists|string|max:255',
                'sale_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sale_additionals.delivery_date' => 'nullable|string|max:255',
                'sale_additionals.delivery_time' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['branch_id'] = $request->branch_id;

            Log::debug('Sale request validated', ['sale_products' => $validated['sale_products']]);



            $sale = DB::transaction(function () use ($validated) {
                $sale = Sale::create([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'salesman_id' => $validated['salesman_id'],
                    'customer_name' => $validated['customer_name'],
                    'customer_address' => $validated['customer_address'] ?? null,
                    'contact_number' => $validated['contact_number'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'credit_days' => $validated['credit_days'] ?? null,
                    'ref_number' => $validated['ref_bill_number'] ?? null,
                    'invoice_number' => $validated['invoice_number'] ?? 'INV-' . now()->format('Ymd') . '-' . rand(1000, 9999),
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? now(),
                    'store_id' => $validated['store_id'],
                    'location_id' => $validated['location_id'],
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? 0,
                    'discount' => $validated['discount'] ?? 0,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? 0,
                    'freight_charge' => $validated['freight_charge'] ?? 0,
                    'excise_duty' => $validated['excise_duty'] ?? 0,
                    'health_insurance' => $validated['health_insurance'] ?? 0,
                    'balance' => $validated['balance'] ?? 0,
                    'payment' => $validated['payment'] ?? "",
                    'taxable_amount' => $validated['taxable_amount'] ?? 0,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? 0,
                    'ref_bill_number' => $validated['ref_bill_number'] ?? null,
                    'round_off_amount' => $validated['round_off_amount'] ?? 0,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'abvt' => $validated['abvt'] ?? false,
                    'cash' => $validated['payment']['cash'] ?? 0,
                    'credit' => $validated['payment']['credit'] ?? 0,
                    'bank' => $validated['payment']['bank'] ?? 0,
                    'is_vatable' => $validated['is_vatable'] ?? false,
                    'total_amount' => $validated['total_amount'] ?? 0,
                    'purchase_id' => $validated['purchase_id'] ?? null,
                    'vat_amount' => $validated['vat_amount'] ?? null,

                    'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                ]);

                Log::debug('Sale created', ['sale_id' => $sale->id]);

                if (isset($validated['sale_additionals']) && !empty($validated['sale_additionals'])) {
                    SaleAdditional::create([
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'sale_id' => $sale->id,
                        'place' => $validated['sale_additionals']['place'] ?? null,
                        'transport' => $validated['sale_additionals']['transport'] ?? null,
                        'vehicle_number' => $validated['sale_additionals']['vehicle_number'] ?? null,
                        'vehicle_name' => $validated['sale_additionals']['vehicle_name'] ?? null,
                        'driver_name' => $validated['sale_additionals']['driver_name'] ?? null,
                        'dispatch_code' => $validated['sale_additionals']['dispatch_code'] ?? null,
                        'driver_contact_number' => $validated['sale_additionals']['driver_contact_number'] ?? null,
                        'delivery_date' => $validated['sale_additionals']['delivery_date'] ?? null,
                        'delivery_time' => $validated['sale_additionals']['delivery_time'] ?? null,
                    ]);

                    Log::debug('Sale additionals created', ['sale_id' => $sale->id]);
                }

                $purchases = collect();

                foreach ($validated['sale_products'] as $index => $productData) {
                    $productId = $productData['product_id'] ?? null;
                    $productModel = null;

                    if ($productId) {
                        $productModel = Product::where('id', $productId)
                            ->where(function ($query) use ($validated) {
                                $query->where('company_id', $validated['company_id'])
                                    ->orWhereNull('company_id');
                            })
                            ->whereNull('deleted_at')
                            ->first();
                        if (!$productModel) {
                            throw new \Exception("Product with ID {$productId} not found at index {$index}");
                        }
                    } elseif (isset($productData['product_name'])) {
                        $productModel = Product::where('name', $productData['product_name'])
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->first();
                        if (!$productModel) {
                            throw new \Exception("Product with name {$productData['product_name']} not found at index {$index}");
                        }
                        $productId = $productModel->id;
                    } else {
                        throw new \Exception("Either product_id or product_name must be provided at index {$index}");
                    }

                    $targetMeasureUnit = MeasureUnit::find($productData['measure_unit_id']);
                    if (!$targetMeasureUnit) {
                        throw new \Exception("Measure unit not found for ID {$productData['measure_unit_id']} at index {$index}");
                    }

                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                    $regularQuantity = $productData['quantity'] ?? 0;
                    $freeQuantity = $productData['free_quantity'] ?? 0;
                    $regularPieces = $this->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);

                    $freePieces = $this->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);

                    $totalRequestedPieces = $regularPieces + $freePieces;


                    Log::debug('Sale product quantities', [
                        'index' => $index,
                        'product_id' => $productId,
                        'regular_quantity' => $regularQuantity,
                        'free_quantity' => $freeQuantity,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_requested_pieces' => $totalRequestedPieces
                    ]);

                    $fieldValuesFlat = $this->flattenFieldValues($productData['field_values'], $index);

                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_stock_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                    Log::debug('Field values processed', [
                        'index' => $index,
                        'product_id' => $productId,
                        'grouped_field_values' => $groupedFieldValues
                    ]);

                    $regularFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'regular')
                        ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();
                    $freeFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'free')
                        ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = !empty($purchaseProductIds) && DB::table('purchase_stock_product_field_values')
                        ->whereIn('purchase_stock_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->exists();

                    Log::debug('Field value validation', [
                        'index' => $index,
                        'product_id' => $productId,
                        'regular_field_value_sets' => $regularFieldValueSets,
                        'free_field_value_sets' => $freeFieldValueSets,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'has_field_values' => $hasFieldValues,
                        'requires_field_values' => $requiresFieldValues
                    ]);

                    if (!$hasFieldValues && $requiresFieldValues) {
                        throw new \Exception("Field values required for product ID {$productId} at index {$index}.");
                    }
                    if ($hasFieldValues && !$requiresFieldValues) {
                        throw new \Exception("Field values provided for product ID {$productId} at index {$index}, but none required.");
                    }
                    if ($hasFieldValues && ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces)) {
                        throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                    }

                    $remainingRegularPieces = $regularPieces;
                    $remainingFreePieces = $freePieces;
                    $allocations = [];
                    $usedQuantityIndexes = [];

                    $query = PurchaseStockProduct::where('product_id', $productId)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->with([

                            'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']), 'measureUnit'])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_stock_product_id'])) {
                        $query->where('id', $productData['purchase_stock_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))
                            ->from('purchase_stock_product_field_values')
                            ->whereColumn('purchase_stock_product_id', 'purchase_stock_products.id')
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $validated['branch_id'])
                            ->whereNull('deleted_at'));
                    }

                    $purchaseProducts = $query->orderBy('created_at')->distinct()->get();

                    if ($purchaseProducts->isEmpty()) {
                        throw new \Exception("No valid purchase products found for product ID {$productId} at index {$index}.");
                    }

                    if ($hasFieldValues) {
                        foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                            $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId) ?? throw new \Exception("Purchase product ID {$purchaseProductId} not found at index {$index}.");
                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;

                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id'], $validated['branch_id']);

                            if ($totalAvailablePieces < 0) {
                                throw new \Exception("Negative stock for purchase_product_id {$purchaseProductId} at index {$index}.");
                            }

                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id'], $validated['branch_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                    throw new \Exception("Invalid quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    throw new \Exception("Duplicate quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                                    Log::debug('Field value mismatch', [
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'quantity_index' => $quantityIndex,
                                        'submitted' => collect($fvSet)->pluck('value', 'product_field_id')->toArray(),
                                        'existing' => $existingFieldValues[$quantityIndex]
                                    ]);
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                            $requestedRegularPieces = count($regularFvByIndex);

                            $requestedFreePieces = count($freeFvByIndex);

                            $totalRequestedForThisProduct = $requestedRegularPieces + $requestedFreePieces;

                            if ($totalRequestedForThisProduct > $totalAvailablePieces) {
                                throw new \Exception("Insufficient stock for purchase_stock_product_id {$purchaseProductId} at index {$index}. Requested: {$totalRequestedForThisProduct}, Available: {$totalAvailablePieces}.");
                            }


                            [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($requestedRegularPieces, $requestedFreePieces, $targetMeasureUnitQuantity);

                            $allocations[] = [
                                'purchase_stock_product_id' => $purchaseProductId,
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
                                'field_values' => array_merge(
                                    array_values($regularFvByIndex),
                                    array_values($freeFvByIndex)
                                ),
                                'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                            ];

                            $remainingRegularPieces -= $requestedRegularPieces;
                            $remainingFreePieces -= $requestedFreePieces;

                            Log::debug('Allocation created', [
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProductId,
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
                                'remaining_regular_pieces' => $remainingRegularPieces,
                                'remaining_free_pieces' => $remainingFreePieces
                            ]);
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {

                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Remaining: Regular {$remainingRegularPieces}, Free {$remainingFreePieces}.");
                        }
                    } else {
                        static $globalStockAllocation = null;
                        if ($globalStockAllocation === null) {
                            $globalStockAllocation = collect();
                        }
                        $purchaseProduct = isset($productData['purchase_stock_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id']) : null;
                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                throw new \Exception("Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}.");
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }
                        $measureUnitIds = $purchaseProducts->pluck('measure_unit_id')->unique()->toArray();
                        $measureUnits = MeasureUnit::whereIn('id', $measureUnitIds)->get()->keyBy('id');
                        $measureUnitsCalc = $measureUnits->map(function ($unit) {
                            return (object) ['quantity' => $unit->quantity ?? 1];
                        })->toArray();

                        Log::debug('PurchaseProducts found', [
                            'product_id' => $productId,
                            'count' => $purchaseProducts->count(),
                            'ids' => $purchaseProducts->pluck('id')->toArray()
                        ]);

                        // Initialize allocations for this product
                        $allocations = [];
                        $remainingRegularPieces = $regularPieces;
                        $remainingFreePieces = $freePieces;

                        $availablePurchaseProducts = $purchaseProducts->filter(function ($purchaseProduct) use ($globalStockAllocation, $validated, $measureUnitsCalc) {
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;
                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);

                            // Adjust available pieces based on previous allocations in this transaction
                            $allocatedPieces = $globalStockAllocation->get($purchaseProduct->id, 0);
                            $remainingPieces = $totalAvailablePieces - $allocatedPieces;

                            return $remainingPieces > 0;
                        })->sortBy('created_at'); // Ensure FIFO order

                        if ($availablePurchaseProducts->isEmpty()) {
                            throw new \Exception("No valid purchase products with available stock found for product ID {$productId} at index {$index}.");
                        }

                        foreach ($availablePurchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
                            $allocatedPieces = $globalStockAllocation->get($purchaseProduct->id, 0);
                            $remainingAvailablePieces = $totalAvailablePieces - $allocatedPieces;

                            if ($remainingAvailablePieces <= 0) {
                                continue;
                            }

                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $remainingAvailablePieces);
                            $allocateRegularPieces = min($remainingRegularPieces, $allocatePieces);
                            $allocateFreePieces = min($remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                            if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => [],
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                ];

                                // Update global stock allocation
                                $globalStockAllocation[$purchaseProduct->id] = ($globalStockAllocation->get($purchaseProduct->id, 0) + $allocateRegularPieces + $allocateFreePieces);

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('FIFO allocation', [
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'global_allocated_pieces' => $globalStockAllocation[$purchaseProduct->id]
                                ]);
                            }
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                        }
                    }

                    foreach ($allocations as $allocation) {
                        $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                        $saleProduct = $sale->saleProducts()->create([
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'sale_id' => $sale->id,
                            'product_id' => $productId,
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'],
                            'amount' => $productData['amount'],
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,

                            'is_vatable' => $productData['is_vatable'] ?? false,
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'mfd' => $allocation['mfd'],
                            'batch_no' => $productData['batch_no'] ?? 'BATCH-' . $purchaseProduct->id . '-' . now()->format('Ymd'),
                            'expiry_date' => $allocation['expiry_date'] ?? null,
                            'name' => $productModel->name,
                        ]);

                        Log::debug('Sale product created', [
                            'index' => $index,
                            'sale_product_id' => $saleProduct->id,
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity']
                        ]);

                        if (!empty($allocation['field_values'])) {
                            foreach ($allocation['field_values'] as $fvSet) {
                                foreach ($fvSet as $fv) {
                                    DB::table('sales_product_field_values')->insert([
                                        'sale_product_id' => $saleProduct->id,
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'product_id' => $productId,
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'],
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $validated['branch_id'],
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }

                            Log::debug('Field values inserted', [
                                'index' => $index,
                                'sale_product_id' => $saleProduct->id,
                                'field_values' => $allocation['field_values']
                            ]);
                        }
                    }
                }

                return $sale;
            });

            Log::debug('Sale transaction completed', ['sale_id' => $sale->id]);

            return response()->json([
                'message' => 'Sale created successfully',
                'data' => $sale->load([
                    'saleProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index', 'asc')->orderBy('product_field_id', 'asc');
                    },
                    'saleAdditionals'
                ])
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error', ['error' => $e->getMessage(), 'sql' => $e->getSql(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }






    public function flattenFieldValues($fieldValues, $index): array
    {
        $flat = [];
        foreach ($fieldValues as $fvSet) {
            foreach ($fvSet as $fv) {
                $flat[] = [
                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? throw new \Exception("Missing purchase_stock_product_id in field values at index {$index}."),
                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                    'product_field_id' => $fv['product_field_id'] ?? null,
                    'value' => $fv['value'] ?? throw new \Exception("Missing value in field values at index {$index}."),
                    'quantity_index' => $fv['quantity_index'] ?? throw new \Exception("Missing quantity_index in field values at index {$index}."),
                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                ];
            }
        }
        return $flat;
    }


    public function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        if ($targetMeasureUnitQuantity <= 0) {
            Log::warning('Invalid target measure unit quantity', ['targetMeasureUnitQuantity' => $targetMeasureUnitQuantity]);
            return [0, 0];
        }


        //For Regular 
        $regularPiecesInt = floor($regularPieces / $targetMeasureUnitQuantity);
        $regularRemainingPieces = $regularPieces - ($regularPiecesInt * $targetMeasureUnitQuantity);
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularPiecesInt + $regularDecimal;

        //For Free Pieces

        $freePiecesInt = floor($freePieces / $targetMeasureUnitQuantity);
        $freeRemainingPieces = $freePieces - ($freePiecesInt * $targetMeasureUnitQuantity);
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
        $freeQuantity = $freePiecesInt + $freeDecimal;


        Log::debug('Converted to target measure unit', [
            'regular_pieces' => $regularPieces,
            'free_pieces' => $freePieces,
            'target_measure_unit_quantity' => $targetMeasureUnitQuantity,
            'regular_quantity' => $regularQuantity,
            'free_quantity' => $freeQuantity
        ]);

        return [$regularQuantity, $freeQuantity];
    }

    public function getUnavailableQuantityIndices($purchaseProduct, int $companyId, int $branchId): array
    {
        $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $returnedIndices = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $purchaseProduct->purchaseStockProductReturns->pluck('id'))
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();



        $adjustedIndices = StockAdjustedFieldValue::whereIn('stock_adjusted_id', $purchaseProduct->stockAdjusted->pluck('id'))
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $unavailableIndices = array_unique(array_merge($soldIndices, $returnedIndices, $adjustedIndices));

        Log::debug('Unavailable quantity indices', [
            'purchase_stock_product_id' => $purchaseProduct->id,
            'sold_indices' => $soldIndices,
            'returned_indices' => $returnedIndices,
            'adjusted_indices' => $adjustedIndices,
            'unavailable_indices' => $unavailableIndices
        ]);

        return $unavailableIndices;
    }




    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Define validation rules (same as store method)
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'salesman_id' => 'nullable|integer|exists:salesmen,id',
                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank_name' => 'nullable|string',
                'payment.bank' => 'nullable|numeric|min:0',
                'invoice_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sales')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $id) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'freight_charge' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'balance' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'ref_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales')
                        ->where(function ($query) use ($request, $id) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at')
                                ->where('id', '!=', $id);
                        }),
                ],
                'roundoff_amount' => 'nullable|numeric|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'vat_amount' => 'nullable|numeric',
                'abvt' => 'nullable|boolean',
                'is_vatable' => 'nullable|boolean',
                'total_amount' => 'nullable|numeric|min:0',
                'sell_entire_batch' => 'nullable|boolean',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'batch_no_sale' => 'nullable|string|max:255',
                'sale_products' => [
                    'required_unless:sell_entire_batch,true',
                    'array',
                    'min:1',
                ],
                'sale_products.*.product_name' => 'required_without:sale_products.*.product_id|string|max:255',
                'sale_products.*.product_id' => 'nullable|integer|exists:products,id',
                'sale_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'sale_products.*.purchase_product_id' => 'nullable',
                'sale_products.*.stock_product_id' => 'nullable',
                'sale_products.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.stock_transfer_id' => 'nullable',
                'sale_products.*.quantity' => 'nullable|string',
                'sale_products.*.free_quantity' => 'nullable|string',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'sale_products.*.batch_no' => 'nullable|string|max:255',
                'sale_products.*.amount' => 'nullable|numeric|min:0',
                'sale_products.*.mfd' => 'nullable|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|string|max:255',
                'sale_products.*.field_values' => 'present|array',
                'sale_products.*.field_values.*' => 'array|min:1',
                'sale_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'sale_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'sale_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'sale_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'sale_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',
                'sale_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
                'sale_additionals' => 'nullable|array',
                'sale_additionals.company_id' => 'nullable|integer|exists:companies,id',
                'sale_additionals.sale_id' => 'nullable|string|max:255',
                'sale_additionals.place' => 'nullable|string|max:255',
                'sale_additionals.transport' => 'nullable|string|max:255',
                'sale_additionals.vehicle_number' => 'nullable|string|max:255',
                'sale_additionals.vehicle_name' => 'nullable|string|max:255',
                'sale_additionals.driver_name' => 'nullable|string|max:255',
                'sale_additionals.dispatch_code' => 'required_if:sale_additionals,exists|string|max:255',
                'sale_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sale_additionals.delivery_date' => 'nullable|string|max:255',
                'sale_additionals.delivery_time' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['branch_id'] = $request->branch_id;
            Log::debug('Sale update request validated', ['sale_products' => $validated['sale_products']]);



            $sale = DB::transaction(function () use ($validated, $id) {
                // Check if sale exists
                $sale = Sale::where('id', $id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->first();

                if (!$sale) {
                    throw new ModelNotFoundException("Sale with ID {$id} not found.");
                }

                // Update sale attributes
                $sale->fill([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'salesman_id' => $validated['salesman_id'],
                    'customer_name' => $validated['customer_name'],
                    'customer_address' => $validated['customer_address'] ?? null,
                    'contact_number' => $validated['contact_number'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'credit_days' => $validated['credit_days'] ?? null,
                    'ref_number' => $validated['ref_number'] ?? null,
                    'invoice_number' => $validated['invoice_number'],
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? now(),
                    'store_id' => $validated['store_id'],
                    'location_id' => $validated['location_id'],
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? 0,
                    'discount' => $validated['discount'] ?? 0,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? 0,
                    'freight_charge' => $validated['freight_charge'] ?? 0,
                    'excise_duty' => $validated['excise_duty'] ?? 0,
                    'health_insurance' => $validated['health_insurance'] ?? 0,
                    'balance' => $validated['balance'] ?? 0,
                    'taxable_amount' => $validated['taxable_amount'] ?? 0,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? 0,
                    'roundoff_amount' => $validated['roundoff_amount'] ?? 0,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'abvt' => $validated['abvt'] ?? false,
                    'cash' => $validated['payment']['cash'] ?? 0,
                    'credit' => $validated['payment']['credit'] ?? 0,
                    'bank' => $validated['payment']['bank'] ?? 0,
                    'is_vatable' => $validated['is_vatable'] ?? false,
                    'total_amount' => $validated['total_amount'] ?? 0,
                    'purchase_id' => $validated['purchase_id'] ?? null,
                    'vat_amount' => $validated['vat_amount'] ?? null,
                    'payment' => $validated['payment'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                ]);

                $sale->save();
                Log::debug('Sale updated', ['sale_id' => $sale->id]);

                // Delete existing sale products and field values
                DB::table('sales_product_field_values')
                    ->whereIn('sale_product_id', SaleProduct::where('sale_id', $sale->id)->pluck('id'))
                    ->delete();
                SaleProduct::where('sale_id', $sale->id)->delete();
                SaleAdditional::where('sale_id', $sale->id)->delete();

                // Handle sale additionals
                if (isset($validated['sale_additionals']) && !empty($validated['sale_additionals'])) {
                    SaleAdditional::create([
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'sale_id' => $sale->id,
                        'place' => $validated['sale_additionals']['place'] ?? null,
                        'transport' => $validated['sale_additionals']['transport'] ?? null,
                        'vehicle_number' => $validated['sale_additionals']['vehicle_number'] ?? null,
                        'vehicle_name' => $validated['sale_additionals']['vehicle_name'] ?? null,
                        'driver_name' => $validated['sale_additionals']['driver_name'] ?? null,
                        'dispatch_code' => $validated['sale_additionals']['dispatch_code'] ?? null,
                        'driver_contact_number' => $validated['sale_additionals']['driver_contact_number'] ?? null,
                        'delivery_date' => $validated['sale_additionals']['delivery_date'] ?? null,
                        'delivery_time' => $validated['sale_additionals']['delivery_time'] ?? null,
                    ]);
                    Log::debug('Sale additionals updated', ['sale_id' => $sale->id]);
                }

                $purchases = collect();
                $consumed = [];   // running ledger: purchase_product_id → pieces used in this request
                foreach ($validated['sale_products'] as $index => $productData) {
                    $productId = $productData['product_id'] ?? null;
                    $productModel = null;
                    if ($productId) {
                        $productModel = Product::where('id', $productId)
                            ->where(function ($query) use ($validated) {
                                $query->where('company_id', $validated['company_id'])
                                    ->orWhereNull('company_id');
                            })
                            ->whereNull('deleted_at')
                            ->first();
                        if (!$productModel) {
                            throw new \Exception("Product with ID {$productId} not found at index {$index}");
                        }
                    } elseif (isset($productData['product_name'])) {
                        $productModel = Product::where('name', $productData['product_name'])
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->first();
                        if (!$productModel) {
                            throw new \Exception("Product with name {$productData['product_name']} not found at index {$index}");
                        }
                        $productId = $productModel->id;
                    } else {
                        throw new \Exception("Either product_id or product_name must be provided at index {$index}");
                    }

                    $targetMeasureUnit = MeasureUnit::find($productData['measure_unit_id']);
                    if (!$targetMeasureUnit) {
                        throw new \Exception("Measure unit not found for ID {$productData['measure_unit_id']} at index {$index}");
                    }

                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                    $regularQuantity = $productData['quantity'] ?? 0;
                    $freeQuantity = $productData['free_quantity'] ?? 0;
                    $regularPieces = $this->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
                    $freePieces = $this->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
                    $totalRequestedPieces = $regularPieces + $freePieces;

                    Log::debug('Sale product quantities', [
                        'index' => $index,
                        'product_id' => $productId,
                        'regular_quantity' => $regularQuantity,
                        'free_quantity' => $freeQuantity,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_requested_pieces' => $totalRequestedPieces
                    ]);

                    $fieldValuesFlat = $this->flattenFieldValues($productData['field_values'], $index);
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_stock_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,

                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                    Log::debug('Field values processed', [
                        'index' => $index,
                        'product_id' => $productId,
                        'grouped_field_values' => $groupedFieldValues
                    ]);

                    $regularFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'regular')
                        ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();
                    $freeFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'free')
                        ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = !empty($purchaseProductIds) && DB::table('purchase_stock_product_field_values')
                        ->whereIn('purchase_stock_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->exists();

                    Log::debug('Field value validation', [
                        'index' => $index,
                        'product_id' => $productId,
                        'regular_field_value_sets' => $regularFieldValueSets,
                        'free_field_value_sets' => $freeFieldValueSets,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'has_field_values' => $hasFieldValues,
                        'requires_field_values' => $requiresFieldValues
                    ]);

                    if (!$hasFieldValues && $requiresFieldValues) {
                        throw new \Exception("Field values required for product ID {$productId} at index {$index}.");
                    }
                    if ($hasFieldValues && !$requiresFieldValues) {
                        throw new \Exception("Field values provided for product ID {$productId} at index {$index}, but none required.");
                    }
                    if ($hasFieldValues && ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces)) {
                        throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                    }

                    $remainingRegularPieces = $regularPieces;
                    $remainingFreePieces = $freePieces;
                    $allocations = [];
                    $usedQuantityIndexes = [];

                    $query = PurchaseStockProduct::where('product_id', $productId)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->with([

                            'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']), 'measureUnit'])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_stock_product_id'])) {
                        $query->where('id', $productData['purchase_stock_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))
                            ->from('purchase_stock_product_field_values')
                            ->whereColumn('purchase_stock_product_id', 'purchase_stock_products.id')
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $validated['branch_id'])
                            ->whereNull('deleted_at'));
                    }

                    $purchaseProducts = $query->orderBy('created_at')->distinct()->get();

                    if ($purchaseProducts->isEmpty()) {
                        throw new \Exception("No valid purchase products found for product ID {$productId} at index {$index}.");
                    }

                    if ($hasFieldValues) {
                        foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                            $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId)
                                ?? throw new \Exception("Purchase product ID {$purchaseProductId} not found at index {$index}.");
                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;
                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id'], $validated['branch_id']);

                            if ($totalAvailablePieces < 0) {
                                throw new \Exception("Negative stock for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                            }

                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id'], $validated['branch_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($p) => $p->saleProductReturns->pluck('id')))
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                    throw new \Exception("Invalid quantity index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    throw new \Exception("Duplicate quantity index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                                    Log::debug('Field value mismatch', [
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'quantity_index' => $quantityIndex,
                                        'submitted' => collect($fvSet)->pluck('value', 'product_field_id')->toArray(),
                                        'existing' => $existingFieldValues[$quantityIndex]
                                    ]);
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();
                            $requestedRegularPieces = count($regularFvByIndex);
                            $requestedFreePieces = count($freeFvByIndex);
                            $totalRequestedForThisProduct = $requestedRegularPieces + $requestedFreePieces;

                            if ($totalRequestedForThisProduct > $totalAvailablePieces) {
                                throw new \Exception("Insufficient stock for purchase_stock_product_id {$purchaseProductId} at index {$index}. Requested: {$totalRequestedForThisProduct}, Available: {$totalAvailablePieces}.");
                            }

                            [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($requestedRegularPieces, $requestedFreePieces, $targetMeasureUnitQuantity);
                            $allocations[] = [
                                'purchase_stock_product_id' => $purchaseProductId,
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
                                'field_values' => array_merge(array_values($regularFvByIndex), array_values($freeFvByIndex)),
                                'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                            ];

                            $remainingRegularPieces -= $requestedRegularPieces;
                            $remainingFreePieces -= $requestedFreePieces;

                            Log::debug('Allocation created (FV)', [
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProductId,
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
                                'remaining_regular_pieces' => $remainingRegularPieces,
                                'remaining_free_pieces' => $remainingFreePieces
                            ]);
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}.");
                        }
                    } else {
                        $purchaseProduct = isset($productData['purchase_stock_product_id'])
                            ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id'])
                            : null;

                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                throw new \Exception("Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}.");
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }

                        $measureUnitIds = $purchaseProducts->pluck('measure_unit_id')->unique()->toArray();
                        $measureUnits = MeasureUnit::whereIn('id', $measureUnitIds)->get()->keyBy('id');
                        $measureUnitsCalc = $measureUnits->map(function ($unit) {
                            return (object) ['quantity' => $unit->quantity ?? 1];
                        })->toArray();

                        Log::debug('Purchase products found', [
                            'product_id' => $productId,
                            'count' => $purchaseProducts->count(),
                            'ids' => $purchaseProducts->pluck('id')->toArray()
                        ]);

                        foreach ($purchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->availablePiecesForSaleUpdate($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id'], $validated['branch_id'], $sale->id)
                                - ($consumed[$purchaseProduct->id] ?? 0);

                            if ($totalAvailablePieces < 0) {
                                throw new \Exception("Negative stock for purchase_stock_product_id {$purchaseProduct->id} at index {$index}.");
                            }

                            if ($totalAvailablePieces <= 0) {
                                continue;
                            }

                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $totalAvailablePieces);
                            $allocateRegularPieces = min($remainingRegularPieces, $allocatePieces);
                            $allocateFreePieces = min($remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                            if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);
                                $allocations[] = [
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => [],
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                ];

                                // record consumed pieces
                                $consumed[$purchaseProduct->id] = ($consumed[$purchaseProduct->id] ?? 0) + $allocateRegularPieces + $allocateFreePieces;

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('FIFO allocation', [
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces
                                ]);
                            }
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                        }
                    }

                    foreach ($allocations as $allocation) {
                        $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                        $saleProduct = $sale->saleProducts()->create([
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'sale_id' => $sale->id,
                            'product_id' => $productId,
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            // 'purchase_product_id' => $productData['purchase_product_id'],
                            // 'stock_product_id' => $productData['stock_product_id'],
                            // 'stock_reconciliation_id' => $productData['stock_reconciliation_id'],
                            // 'stock_transfer_id' => $productData['stock_transfer_id'],
                            // 'stock_adjustment_id' => $productData['stock_adjustment_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'],
                            'amount' => ($productData['price'] * $allocation['quantity']) - ($productData['discount_amount'] ?? 0),
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'is_vatable' => $productData['is_vatable'] ?? false,
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'mfd' => $allocation['mfd'],
                            'batch_no' => $productData['batch_no'] ?? 'BATCH-' . $purchaseProduct->id . '-' . now()->format('Ymd'),
                            'expiry_date' => $allocation['expiry_date'] ?? null,
                            'name' => $productModel->name,
                        ]);

                        Log::debug('Sale product created', [
                            'index' => $index,
                            'sale_product_id' => $saleProduct->id,
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity']
                        ]);

                        if (!empty($allocation['field_values'])) {
                            foreach ($allocation['field_values'] as $fvSet) {
                                foreach ($fvSet as $fv) {
                                    DB::table('sales_product_field_values')->insert([
                                        'sale_product_id' => $saleProduct->id,
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                        'product_id' => $productId,
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'],
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $validated['branch_id'],
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }
                            Log::debug('Field values inserted', [
                                'index' => $index,
                                'sale_product_id' => $saleProduct->id,
                                'field_values' => $allocation['field_values']
                            ]);
                        }
                    }
                }

                return $sale;
            });

            Log::debug('Sale transaction completed', ['sale_id' => $sale->id]);

            return response()->json([
                'message' => 'Sale updated successfully',
                'data' => $sale->load([
                    'saleProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index', 'asc')->orderBy('product_field_id', 'asc');
                    },
                    'saleAdditionals'
                ])
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::error('Model not found', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Sale not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error', ['error' => $e->getMessage(), 'sql' => $e->getSql(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }


    public function show(Request $request, $id): JsonResponse
    {
        try {
            $item = Sale::with('saleProducts.fieldValues')->findOrFail($id);
            $productIds = $item->saleProducts->pluck('product_id')->unique();

            // Step 3: Load measure units
            $productMeasureUnits = ProductList::whereIn('product_id', $productIds)
                ->where('company_id', $request->company_id)
                ->with(['measureUnit:id,name,quantity'])
                ->get()
                ->groupBy('product_id');

            $request->merge([

                'company_id' => $request->company_id ?? null,
                'branch_id' => $request->branch_id ?? null,
            ]);


            foreach ($item->saleProducts as $saleProducts) {

                $units = $productMeasureUnits->get($saleProducts->product_id, collect())
                    ->pluck('measureUnit');
                $saleProducts->setRelation('measure_units', $units);
                $productID = $saleProducts->product_id;
                $productCode = Product::where('id', $productID)->value('product_unique_id');
                $productName = Product::where('id', $productID)->value('name');
                $response = AvailableQuantityService::getAvailableProductDetailsById($request, $productID);
                $responseData = $response->getData(true);


                $availableMap = collect($responseData['data'] ?? [])
                    ->mapWithKeys(function ($item) {
                        return [$item['product_id'] => $item['available_quantity']];
                    });

                // field_values (add field name)
                $saleProducts->setRelation(
                    'field_values',
                    $saleProducts->fieldValues->map(function ($fv) {
                        $fv->name = $fv->productField->name ?? null;
                        return $fv;
                    })
                );

                // inject remaining quantity
                $saleProducts->product_name = $productName;
                unset($saleProducts->name);
                $saleProducts->product_code = $productCode;
                $saleProducts->remaining_quantity = $availableMap[$saleProducts->product_id] ?? 0;
            }
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'An Unexpected error occurred'], 500);
        }
    }





    private function getSalesByCustomer(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $customerID = $request->input('customer_id');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesByCustomer($customerID, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales found for the specified customer'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }


    public function getSalesByBatch(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'batch_no' => 'required|exists:sales,batch_no',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $batchNo = $request->input('batch_no');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesByBatch($batchNo, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales found for the specified batch'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function getAllExpiryDates(): JsonResponse
    {
        $expiryDates = SaleProduct::select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date', 'asc')
            ->pluck('expiry_date');

        return response()->json([
            'message' => 'Expiry dates retrieved successfully',
            'data' => $expiryDates
        ], 200);
    }


    public function getSalesByExpiryDate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'expiry_date' => 'required|exists:sale_products,expiry_date',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $expiryDate = $request->input('expiry_date');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesByExpiryDate($expiryDate, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales found for the specified Expiry Date'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $sale = Sale::findOrFail($id);

            if (
                $sale->salesReturnUse()->exists() ||
                $sale->saleProductUse()->exists() ||
                $sale->saleAdditionalUse()->exists()
            ) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Sale cannot be deleted because it has related sales or return records.'
                ], 400);
            }

            $sale->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sale deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'not_found',
                'message' => 'Sale not found!'
            ], 404);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the sale.'
            ], 500);

        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the sale.'
            ], 500);
        }
    }

    // public function filterByBarcode(Request $request): JsonResponse
// {
//     try {
//         \Log::info('Filter Sale request: ', $request->all());

    //         // Validate request
//         $validator = Validator::make($request->all(), [
//             'barcode' => 'required_without:product_unique_id',
//             'product_unique_id' => 'required_without:barcode',
//             'company_id' => 'required|integer|exists:companies,id',
//             'response_unit_id' => 'nullable|integer|exists:measure_units,id',
//         ]);

    //         if ($validator->fails()) {
//             return response()->json(['errors' => $validator->errors()], 422);
//         }

    //         $companyId = $request->company_id;
//         $responseUnitId = $request->response_unit_id ?? null;

    //         // Get product by barcode or unique id
//         if ($request->filled('barcode')) {
//             $productList = ProductList::where('company_id', $companyId)
//                 ->where('barcode', $request->barcode)
//                 ->first();

    //             if (!$productList) {
//                 return response()->json([
//                     'error' => 'No product found for this barcode',
//                     'searched_value' => $request->barcode
//                 ], 404);
//             }

    //             $product = Product::with(['measureUnit', 'productLists.measureUnit', 'productFieldValues'])
//                 ->find($productList->product_id);
//         } else {
//             $product = Product::with(['measureUnit', 'productLists.measureUnit', 'productFieldValues'])
//                 ->where('company_id', $companyId)
//                 ->where('product_unique_id', $request->product_unique_id)
//                 ->first();

    //             if (!$product) {
//                 return response()->json([
//                     'error' => 'No product found for this product_unique_id',
//                     'searched_value' => $request->product_unique_id
//                 ], 404);
//             }
//         }

    //         // Measure units
//         $measureUnits = MeasureUnit::where('company_id', $companyId)
//             ->whereNull('deleted_at')
//             ->get()
//             ->keyBy('id');

    //         // Purchase products
//         $purchaseProducts = PurchaseProduct::with('purchase')
//             ->where('company_id', $companyId)
//             ->where('product_id', $product->id)
//             ->whereNull('deleted_at')
//             ->get();

    //         // Fetch returns, sales, and sales returns
//         $purchaseProductIds = $purchaseProducts->pluck('id')->toArray();

    //         $purchaseProductReturns = \DB::table('purchase_product_returns')
//             ->whereIn('purchase_product_id', $purchaseProductIds)
//             ->where('company_id', $companyId)
//             ->whereNull('deleted_at')
//             ->get()
//             ->groupBy('purchase_product_id');

    //         $saleProducts = \DB::table('sale_products')
//             ->whereIn('purchase_product_id', $purchaseProductIds)
//             ->where('company_id', $companyId)
//             ->whereNull('deleted_at')
//             ->get()
//             ->groupBy('purchase_product_id');

    //         $salesReturnProducts = \DB::table('sales_return_products')
//             ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
//             ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
//             ->where('sales_return_products.company_id', $companyId)
//             ->whereNull('sales_return_products.deleted_at')
//             ->get()
//             ->groupBy('purchase_product_id');

    //         // Calculate quantities per purchase product
//         $purchaseProducts = $purchaseProducts->map(function ($pp) use ($measureUnits, $purchaseProductReturns, $saleProducts, $salesReturnProducts) {
//             $unitQty = $measureUnits[$pp->measure_unit_id]->quantity ?? 1;

    //             $totalPurchased = ($pp->quantity + $pp->free_quantity) * $unitQty;
//             $totalReturned = collect($purchaseProductReturns[$pp->id] ?? [])->sum(function ($ret) use ($measureUnits) {
//                 $unitQty = $measureUnits[$ret->measure_unit_id]->quantity ?? 1;
//                 return ($ret->quantity + $ret->free_quantity) * $unitQty;
//             });
//             $totalSold = collect($saleProducts[$pp->id] ?? [])->sum(function ($sale) use ($measureUnits) {
//                 $unitQty = $measureUnits[$sale->measure_unit_id]->quantity ?? 1;
//                 return ($sale->quantity + $sale->free_quantity) * $unitQty;
//             });
//             $totalSalesReturn = collect($salesReturnProducts[$pp->id] ?? [])->sum(function ($ret) use ($measureUnits) {
//                 $unitQty = $measureUnits[$ret->measure_unit_id]->quantity ?? 1;
//                 return ($ret->quantity + $ret->free_quantity) * $unitQty;
//             });

    //             $available = max($totalPurchased - $totalReturned - $totalSold + $totalSalesReturn, 0);

    //             return (object) [
//                 'purchase_product_id' => $pp->id,
//                 'purchase_id' => $pp->purchase_id,
//                 'purchase_bill_number' => $pp->purchase->purchase_bill_number ?? null,
//                 'invoice_date' => $pp->purchase->invoice_date ?? null,
//                 'product_id' => $pp->product_id,
//                 'product_name' => $pp->product_name,
//                 'product_code' => $pp->product_code,
//                 'mfd' => $pp->mfd,
//                 'quantity' => $pp->quantity,
//                 'free_quantity' => $pp->free_quantity,
//                 'price' => $pp->price,
//                 'is_vatable' => $pp->is_vatable,
//                 'measure_unit_id' => $pp->measure_unit_id,
//                 'measure_unit_name' => $pp->measureUnit?->name,
//                 'measure_unit_quantity' => $pp->measureUnit?->quantity ?? 1,
//                 'expiry_date' => $pp->expiry_date,
//                 'return_quantity' => $totalReturned,
//                 'sale_quantity' => $totalSold,
//                 'sales_return_quantity' => $totalSalesReturn,
//                 'available_quantity' => $available,
//                 'purchased_quantity' => $totalPurchased,
//             ];
//         });

    //         $availableQuantity = $purchaseProducts->sum('available_quantity');

    //         // Primary measure unit
//         $primary = $product->productLists->firstWhere('is_primary', true)?->measureUnit;

    //         $data = [
//             "product_id" => $product->id,
//             "product_name" => $product->name,
//             "product_code" => $product->product_unique_id,
//             "barcode" => $product->productLists->first()?->barcode,
//             "is_vatable" => (bool)$product->is_vatable,
//             "measure_unit_id" => $primary?->id ?? $product->measure_unit_id,
//             "measure_unit_quantity" => $primary?->quantity ?? $product->measureUnit?->quantity ?? 1,
//             "retail_sale_price" => $product->retail_sales_price,
//             "avg_price" => $product->purchase_rate,
//                 "min_price" => $product->purchase_rate,
//                 "latest_price" => $product->purchase_rate,
//             "measure_units_used" => $product->productLists->map(fn($pl) => [
//                 "id" => $pl->measure_unit_id,
//                 "name" => $pl->measureUnit?->name,
//                 "measure_unit_quantity" => $pl->measureUnit?->quantity ?? 1
//             ])->unique('id')->values()->toArray(),
//             "avg_sales_price" => $product->retail_sales_price,
//             "min_sales_price" => $product->retail_sales_price,
//             "latest_sales_price" => $product->retail_sales_price,
//             "purchased_quantity" => $purchaseProducts->sum('purchased_quantity'),
//             "return_quantity" => $purchaseProducts->sum('return_quantity'),
//             "sale_quantity" => $purchaseProducts->sum('sale_quantity'),
//             "sales_return_quantity" => $purchaseProducts->sum('sales_return_quantity'),
//             "available_quantity" => $availableQuantity,
//             "expiry_dates" => [],
//             "field_values" => $product->productFieldValues->map(fn($fv, $index) => [
//                 "purchase_product_id" => $fv->purchase_product_id,
//                 "product_field_id" => $fv->product_field_id,
//                 "name" => $fv->name,
//                 "value" => $fv->value,
//                 "quantity_index" => $index
//             ])->toArray(),
//             "purchase_products" => $purchaseProducts->values()->toArray()
//         ];

    //         return response()->json([
//             "message" => "Product details retrieved",
//             "data" => [$data]
//         ]);

    //     } catch (\Exception $e) {
//         \Log::error('Error in filterByBarcode (SaleController): ' . $e->getMessage());
//         return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
//     }
// }


    public function filterByBarcode(Request $request): JsonResponse
    {
        try {
            \Log::info('Filter Sale request: ', $request->all());

            // Validate request
            $validator = Validator::make($request->all(), [
                'barcode' => 'required_without:product_unique_id',
                'product_unique_id' => 'required_without:barcode',
                'company_id' => 'required|integer|exists:companies,id',
                'response_unit_id' => 'nullable|integer|exists:measure_units,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $companyId = $request->company_id;
            $responseUnitId = $request->response_unit_id ?? null;

            // Fetch product by barcode or unique_id
            if ($request->filled('barcode')) {
                $productList = ProductList::where('company_id', $companyId)
                    ->where('barcode', $request->barcode)
                    ->first();

                if (!$productList) {
                    return response()->json([
                        'error' => 'No product found for this barcode',
                        'searched_value' => $request->barcode
                    ], 404);
                }

                $product = Product::with(['measureUnit', 'productLists.measureUnit', 'productFieldValues'])
                    ->find($productList->product_id);
            } else {
                $product = Product::with(['measureUnit', 'productLists.measureUnit', 'productFieldValues'])
                    ->where('company_id', $companyId)
                    ->where('product_unique_id', $request->product_unique_id)
                    ->first();

                if (!$product) {
                    return response()->json([
                        'error' => 'No product found for this product_unique_id',
                        'searched_value' => $request->product_unique_id
                    ], 404);
                }
            }

            // Call the shared function for availability & details
            $productsData = $this->getAvailableProductsDetails(
                $product->id,
                null,
                $companyId,
                $responseUnitId
            );

            // Add barcode to each product in data
            if (!empty($productsData['data'])) {
                foreach ($productsData['data'] as &$item) {
                    $item['barcode'] = $product->productLists->first()?->barcode;
                }
            }

            return response()->json([
                'message' => !empty($productsData['data']) ? 'Product details retrieved' : 'No matching product found',
                'data' => $productsData['data'] ?: []
            ], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('Model not found in filterByBarcode', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'No matching product found', 'data' => []], 200);
        } catch (QueryException $e) {
            \Log::error('Database query error in filterByBarcode', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Database query error',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in filterByBarcode', ['error' => $e->getMessage(), 'request' => $request->all()]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }




}
