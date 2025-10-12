<?php

namespace App\Services;

use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductReturn;
use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProductReturn;


class StockAdjustmentService
{

    public function getAvailableProductsForSale($purchaseType, $companyId)
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
                    'purchaseProductReturns' => fn($q) => $q
                        ->whereNull('purchase_product_returns.deleted_at')
                        ->where('purchase_product_returns.company_id', $companyId)
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
                        ->whereNull('purchase_product_field_values.deleted_at')
                        ->where('purchase_product_field_values.company_id', $companyId)
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
                    return $saleProduct ? $saleProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->purchaseProductReturns->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['purchase_return_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseProductReturn::find($fv->purchase_return_product_id);
                    return $returnProduct ? $returnProduct->purchase_product_id : null;
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
                    return $pp->purchaseProductReturns->reduce(
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

    private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null, ?int $responseUnitId = null): array
    {
        Log::debug('Fetching detailed available products with purchase products', [
            'product_id' => $productId,
            'product_name' => $productName,
            'company_id' => $companyId,
            'response_unit_id' => $responseUnitId
        ]);

        try {
            DB::enableQueryLog();



            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

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

            if ($productName) {
                $productNameForUnit = Product::where('name', $productName)->first();
                $productForUnit = $productNameForUnit->id;
            }

            if ($productId) {
                $productNameForUnit = Product::where('id', $productId)->first();
                $productForUnit = $productNameForUnit->id;
            }


            $retailSalePrice = Product::where('id', $productForUnit)->pluck('retail_sales_price')->first();
            $productSoldPrice = SaleProduct::where('product_id', $productForUnit)
                ->orderByDesc('created_at')
                ->get(['price', 'created_at']);


            $avgPrice = $productSoldPrice->avg('price');
            $minPrice = $productSoldPrice->min('price');
            $latestSoldPrice = $productSoldPrice->first()->price ?? 0;



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



            if ($getProductForMeasureUnits) {
                // Step 1: Get measure_unit_id from Product
                $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

                // Step 2: Add all measure_unit_ids from ProductList
                $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

                // Step 3: Merge and make unique
                $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();


            } else {
                echo 'Product not found';
            }

            $measureUnitsUsed = MeasureUnit::whereIn('id', $allUnitIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity']) // Get as a collection
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });



            $purchaseProducts = PurchaseStockProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->with([
                    'purchase' => fn($q) => $q->select(['id', 'company_id', 'purchase_bill_number', 'invoice_date'])
                        ->whereNull('deleted_at'),
                    'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),
                    'saleProducts' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q->whereNull('deleted_at')
                                ->where('company_id', $companyId)
                                ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])])
                        ]),
                    'fieldValues' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with([
                            'productField' => fn($q) => $q->select(['id', 'name', 'company_id'])
                                ->where('company_id', $companyId)
                                ->whereNull('deleted_at')
                        ])
                ])
                // Optional: Add period filtering if needed
                // ->whereHas('purchase', fn($q) => $q->whereBetween('invoice_date', [$startDate, $endDate]))
                ->orderBy('created_at', 'asc')
                ->get();

            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', [
                    'product_ids' => $productIds,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            // Fetch quantity indexes
            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['sale_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $saleProduct = SaleProduct::find($fv->sale_product_id);
                    return $saleProduct ? $saleProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->purchaseProductReturns->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['purchase_return_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseProductReturn::find($fv->purchase_return_product_id);
                    return $returnProduct ? $returnProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            // Process results
            $result = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $measureUnitsCalc, $measureUnitsUsed, $latestSoldPrice, $minPrice, $avgPrice, $retailSalePrice, $primaryMeasureUnitQuantity, $primarayMeasureUnitId) {

                $allFieldValues = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->flatMap(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes) {
                        return $pp->fieldValues->filter(function ($fv) use ($soldQuantityIndexes, $returnedQuantityIndexes, $pp) {
                            $excludedIndexes = array_unique(array_merge(
                                $soldQuantityIndexes[$pp->id] ?? [],
                                $returnedQuantityIndexes[$pp->id] ?? []
                            ));
                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField->name ?? null,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values();
                    })->toArray();


                $productPurchaseProducts = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->map(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $measureUnitsCalc) {
                        // Calculate purchased pieces
                        $purchasedPieces = $this->calculatePieces(
                            ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                            measureUnitQuantity: isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1
                        );

                        // Calculate return pieces, capped at purchased pieces
                        $returnPieces = $pp->purchaseProductReturns->reduce(
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
                        $availablePieces = $this->calculateAvailablePieces($pp, $companyId, $measureUnitsCalc);

                        // Collect field values for this purchase product
                        $fieldValues = $pp->fieldValues->filter(function ($fv) use ($soldQuantityIndexes, $returnedQuantityIndexes, $pp) {
                            $excludedIndexes = array_unique(array_merge(
                                $soldQuantityIndexes[$pp->id] ?? [],
                                $returnedQuantityIndexes[$pp->id] ?? []
                            ));
                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField->name ?? null,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values()->toArray();

                        return [
                            'purchase_product_id' => $pp->id,
                            'purchase_id' => $pp->purchase_id,
                            'purchase_bill_number' => $pp->purchase->purchase_bill_number ?? null,
                            'invoice_date' => $pp->purchase->invoice_date ?? null,
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
                            'return_quantity' => $returnPieces, // In pieces
                            'sale_quantity' => $salePieces, // In pieces
                            'sales_return_quantity' => $salesReturnPieces, // In pieces
                            'available_quantity' => max($availablePieces, 0), // In pieces
                            'purchased_quantity' => $purchasedPieces, // In pieces
    
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

                $availablePieces = $purchasedPieces - $returnPieces - $salePieces + $salesReturnPieces;

                $salesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->pluck('price');
                $lastSalesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at')
                    ->value('price');

                // Aggregate all field values for the product (optional, if you still want them at product level)

                return [
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    // 'min_price' => !empty($productPurchaseProducts) ? min(array_column($productPurchaseProducts, 'price')) : 0,
                    'is_vatable' => (bool) $product->is_vatable,
                    'measure_unit_id' => $primarayMeasureUnitId->id ?? null, // No specific measure unit for pieces

                    'measure_unit_quantity' => $primaryMeasureUnitQuantity, // 1 piece = 1 
                    'retail_sale_price' => $retailSalePrice ?? 0,
                    'avg_price' => $avgPrice ?? 0,
                    'min_price' => $minPrice ?? 0,
                    'latest_price' => $latestSoldPrice ?? 0,
                    'measure_units_used' => $measureUnitsUsed,
                    'avg_sales_price' => round($salesPrice->avg(), 2) ?: null,
                    'min_sales_price' => round($salesPrice->min(), 2) ?: null,
                    'latest_sales_price' => round($lastSalesPrice, 2) ?: null,
                    'purchased_quantity' => $purchasedPieces, // In pieces
                    'return_quantity' => $returnPieces, // In pieces
                    'sale_quantity' => $salePieces, // In pieces
                    'sales_return_quantity' => $salesReturnPieces, // In pieces
                    'available_quantity' => max($availablePieces, 0), // In pieces
                    'expiry_dates' => array_filter(array_unique(array_column($productPurchaseProducts, 'expiry_date'))),
                    'field_values' => $allFieldValues, // Include aggregated field values
                    'purchase_products' => $productPurchaseProducts

                ];
            })->filter()->values()->toArray();
            return [
                'message' => 'Product details retrieved',
                'data' => $result
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching detailed available products', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
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
                'company_id' => 'nullable|integer|exists:companies,id,deleted_at,NULL',
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
                ? collect($this->getAvailableProductsDetails(null, null, $companyId)['data'])
                : $this->getAvailableProductsForSale($purchaseType, $companyId);

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


    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity', ['measureUnitQuantity' => $measureUnitQuantity]);
            return 0;
        }


        $integerPart = floor($quantity);

        $decimalPart = $quantity - $integerPart;

        $decimalStr = (string) $decimalPart;
        $decimalPieces = $decimalStr > 0 ? (int) str_replace('.', '', (string) $decimalStr) : 0;

        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function calculateAvailablePieces($purchaseProduct, int $companyId, $measureUnitsCalc): int
    {
        $purchaseMeasureUnitQuantity = isset($measureUnitsCalc[$purchaseProduct->measure_unit_id]) ? $measureUnitsCalc[$purchaseProduct->measure_unit_id]->quantity : 1;

        Log::debug('Measure unit quantity', [
            'purchase_product_id' => $purchaseProduct->id,
            'measure_unit_id' => $purchaseProduct->measure_unit_id,
            'purchaseMeasureUnitQuantity' => $purchaseMeasureUnitQuantity
        ]);

        if ($purchaseMeasureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity for purchase product', [
                'purchase_product_id' => $purchaseProduct->id,
                'measureUnitQuantity' => $purchaseMeasureUnitQuantity
            ]);
            return 0;
        }

        // Log purchase product data
        Log::debug('Purchase product data', [
            'purchase_product_id' => $purchaseProduct->id,
            'quantity' => $purchaseProduct->quantity ?? 0,
            'free_quantity' => $purchaseProduct->free_quantity ?? 0
        ]);

        // Prioritize field values if they exist
        $fieldValues = $purchaseProduct->fieldValues->whereNull('deleted_at')->groupBy('quantity_index');
        if ($fieldValues->isNotEmpty()) {
            $unavailableIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $companyId);
            $availablePieces = $fieldValues->filter(function ($fv, $index) use ($unavailableIndices) {
                return !in_array($index, $unavailableIndices);
            })->count();

            Log::debug('Calculated available pieces via field values', [
                'purchase_product_id' => $purchaseProduct->id,
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

        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns->reduce(
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

        $availablePieces = $totalPurchasedPieces - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces;

        if ($availablePieces < 0) {
            Log::warning('Negative available pieces detected', [
                'purchase_product_id' => $purchaseProduct->id,
                'total_purchased' => $totalPurchasedPieces,
                'purchase_returned' => $purchaseReturnedPieces,
                'sold' => $soldPieces,
                'sales_returned' => $salesReturnedPieces,
                'available' => $availablePieces
            ]);
        }

        Log::debug('Calculated available pieces via quantities', [
            'purchase_product_id' => $purchaseProduct->id,
            'total_purchased' => $totalPurchasedPieces,
            'purchase_returned' => $purchaseReturnedPieces,
            'sold' => $soldPieces,
            'sales_returned' => $salesReturnedPieces,
            'available' => $availablePieces
        ]);

        return max(0, (int) $availablePieces); // Remove floor, cast to int
    }

    private function getUnavailableQuantityIndices($purchaseProduct, int $companyId): array
    {
        $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProduct->purchaseProductReturns->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $unavailableIndices = array_unique(array_merge($soldIndices, $returnedIndices));

        Log::debug('Unavailable quantity indices', [
            'purchase_product_id' => $purchaseProduct->id,
            'sold_indices' => $soldIndices,
            'returned_indices' => $returnedIndices,
            'unavailable_indices' => $unavailableIndices
        ]);

        return $unavailableIndices;
    }


}