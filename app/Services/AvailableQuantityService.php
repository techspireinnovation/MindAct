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

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Validation\Rule;


class AvailableQuantityService
{

     public function getProductDetailsByInput(Request $request, $productID): JsonResponse
    {
        try {

            // Validate input
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'branch_id' => 'required|integer|exists:branches,id',
                'product_code' => 'nullable|string|max:255',
                'product_name' => 'nullable|string|max:255',
                'barcode' => 'nullable|string|max:255',
                'purchase_bill_number' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }

            if (!$request->hasAny(['product_code', 'product_name', 'barcode', 'purchase_bill_number'])) {
                Log::warning('No valid search parameters provided', ['request' => $request->all()]);
                return response()->json(['error' => 'At least one of product_code, product_name, barcode, or purchase_bill_number is required'], 422);
            }

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $productCode = $request->input('product_code');
            $productName = trim(strtolower($request->input('product_name')));
            $barcode = $request->input('barcode');
            $purchaseBillNumber = $request->input('purchase_bill_number');

            Log::debug('Input parameters', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'product_name' => $productName,
                'barcode' => $barcode,
                'purchase_bill_number' => $purchaseBillNumber
            ]);


            DB::enableQueryLog();


            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');


            $purchaseProductsQuery = DB::table('purchase_stock_products')
                ->select([
                    'purchase_stock_products.id as purchase_stock_product_id',
                    'purchase_stock_products.purchase_id',
                    'purchase_stock_products.product_id',
                    'purchase_stock_products.product_name',
                    'purchase_stock_products.product_code',
                    'purchase_stock_products.quantity',
                    'purchase_stock_products.free_quantity',
                    'purchase_stock_products.expiry_date',
                    'purchase_stock_products.price',
                    'purchase_stock_products.is_vatable',
                    'purchase_stock_products.measure_unit_id',
                    'measure_units.name as measure_unit_name',
                    'measure_units.quantity as measure_unit_quantity',
                    // 'purchases.purchase_bill_number',
                    // 'purchases.invoice_date',
                ])
                ->join('measure_units', 'purchase_stock_products.measure_unit_id', '=', 'measure_units.id')
                // ->leftJoin('purchases', function ($join) use ($companyId) {
                //     $join->on('purchase_products.purchase_id', '=', 'purchases.id')
                //         ->where('purchases.company_id', $companyId)
                //         ->whereNull('purchases.deleted_at');
                // })
                ->where('purchase_stock_products.company_id', $companyId)
                ->where('purchase_stock_products.branch_id', $branchId)
                ->whereNull('purchase_stock_products.deleted_at')
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->when($productCode, fn($q) => $q->where('purchase_stock_products.product_code', $productCode))
                ->when($productName, fn($q) => $q->whereRaw('LOWER(purchase_stock_products.product_name)  = ?', [strtolower($productName)]))
                ->when($barcode, fn($q) => $q->whereIn('purchase_stock_products.id', function ($subQuery) use ($barcode, $companyId) {
                    $subQuery->select('purchase_stock_product_id')
                        ->from('purchase_stock_product_field_values')
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->where('value', $barcode)
                        ->where('product_field_id', env('BARCODE_FIELD_ID', 1));
                }))
                // ->when($purchaseBillNumber, fn($q) => $q->where('purchases.purchase_bill_number', $purchaseBillNumber))
                // ->orderBy('purchases.invoice_date', 'ASC')
                ->orderBy('purchase_stock_products.created_at', 'ASC');

            // Fetch purchase products
            $purchaseProducts = $purchaseProductsQuery->get();
            Log::debug('Purchase products query results', [
                'purchase_stock_products' => $purchaseProducts,
                'query' => $purchaseProductsQuery->toSql(),
                'bindings' => $purchaseProductsQuery->getBindings()
            ]);

            if ($purchaseProducts->isEmpty()) {
                Log::info('No purchase products found', [
                    'company_id' => $companyId,
                    'branch_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products found matching the criteria'], 404);
            }

            // Fetch related data for calculations
            $purchaseProductIds = $purchaseProducts->pluck('purchase_stock_product_id')->toArray();

            $productId = $productID;


            $purchaseProductReturns = DB::table('purchase_stock_product_returns')
                ->select([
                    'purchase_stock_product_returns.purchase_stock_product_id',
                    'purchase_stock_product_returns.quantity',
                    'purchase_stock_product_returns.free_quantity',
                    'purchase_stock_product_returns.measure_unit_id',
                ])
                ->whereIn('purchase_stock_product_returns.purchase_stock_product_id', $purchaseProductIds)
                ->where('purchase_stock_product_returns.company_id', $companyId)
                ->whereNull('purchase_stock_product_returns.deleted_at')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $saleProducts = DB::table('sale_products')
                ->select([
                    'sale_products.purchase_product_id',
                    'sale_products.quantity',
                    'sale_products.free_quantity',
                    'sale_products.measure_unit_id',
                ])
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sale_products.company_id', $companyId)
                ->whereNull('sale_products.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            $salesReturnProducts = DB::table('sales_return_products')
                ->select([
                    'sale_products.purchase_product_id',
                    'sales_return_products.quantity',
                    'sales_return_products.free_quantity',
                    'sales_return_products.measure_unit_id',
                ])
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sales_return_products.company_id', $companyId)
                ->whereNull('sales_return_products.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            // Fetch field values and quantity indexes
            $soldQuantityIndexes = DB::table('sales_product_field_values')
                ->select([
                    'sale_products.purchase_product_id',
                    'sales_product_field_values.quantity_index'
                ])
                ->join('sale_products', 'sales_product_field_values.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sale_products.company_id', $companyId)
                ->whereNull('sale_products.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = DB::table('purchase_stock_product_return_field_values')
                ->select([
                    'purchase_stock_product_returns.purchase_stock_product_id',
                    'purchase_stock_product_return_field_values.quantity_index'
                ])
                ->join('purchase_stock_product_returns', 'purchase_stock_product_return_field_values.purchase_stock_product_return_id', '=', 'purchase_stock_product_returns.id')
                ->whereIn('purchase_stock_product_returns.purchase_stock_product_id', $purchaseProductIds)
                ->where('purchase_stock_product_returns.company_id', $companyId)
                ->where('purchase_stock_product_returns.branch_id', $branchId)
                ->whereNull('purchase_stock_product_returns.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_stock_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $fieldValues = DB::table('purchase_stock_product_field_values')
                ->select([
                    'purchase_stock_product_field_values.purchase_stock_product_id',
                    'purchase_stock_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'purchase_stock_product_field_values.value',
                    'purchase_stock_product_field_values.quantity_index'

                ])
                ->leftJoin('product_fields', fn($join) => $join->on('purchase_stock_product_field_values.product_field_id', '=', 'product_fields.id')
                    ->where('product_fields.company_id', $companyId)
                    ->whereNull('product_fields.deleted_at'))
                ->leftJoin('purchase_stock_products', 'purchase_stock_product_field_values.purchase_stock_product_id', '=', 'purchase_stock_products.id')
                ->join('product_field_values', function ($join) use ($companyId, $productId) {
                    $join->on('purchase_stock_product_field_values.product_field_id', '=', 'product_field_values.product_field_id')

                        ->where('product_field_values.company_id', $companyId)
                        ->whereIn('product_field_values.product_id', $productId)
                        ->whereRaw('product_field_values.product_id = purchase_stock_products.product_id')
                        ->whereNull('product_field_values.deleted_at');
                })

                ->whereIn('purchase_stock_product_field_values.purchase_stock_product_id', $purchaseProductIds)
                ->where('purchase_stock_product_field_values.company_id', $companyId)
                ->where('purchase_stock_product_field_values.branch_id', $branchId)
                ->whereNull('purchase_stock_product_field_values.deleted_at')
                ->orderBy('purchase_stock_product_field_values.quantity_index', 'ASC')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $saleReturnFieldValues = DB::table('sale_return_product_field_values')
                ->select([
                    'sale_products.purchase_product_id',
                    'sale_return_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'sale_return_product_field_values.value',
                    'sale_return_product_field_values.quantity_index'
                ])
                ->join('sales_return_products', 'sale_return_product_field_values.sale_return_product_id', '=', 'sales_return_products.id')
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->leftJoin('product_fields', fn($join) => $join->on('sale_return_product_field_values.product_field_id', '=', 'product_fields.id')
                    ->where('product_fields.company_id', $companyId))
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sale_return_product_field_values.company_id', $companyId)
                ->whereNull('sale_return_product_field_values.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            // Process purchase products
            $purchaseProducts = $purchaseProducts->map(function ($pp) use ($measureUnitsCalc, $purchaseProductReturns, $saleProducts, $salesReturnProducts) {
                $measureUnitId = $pp->measure_unit_id ?? null;
                $unitData = isset($measureUnitsCalc[$measureUnitId]) ? [
                    'id' => $measureUnitsCalc[$measureUnitId]->id,
                    'name' => $measureUnitsCalc[$measureUnitId]->name,
                    'quantity' => $measureUnitsCalc[$measureUnitId]->quantity ?? 1
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1
                ];

                // Calculate total purchase quantity in pieces
                $quantity = $pp->quantity ?? 0; // e.g., 2.2
                $freeQuantity = $pp->free_quantity ?? 0; // e.g., 2.3
                $totalQuantity = $quantity + $freeQuantity; // 4.5
                $decimalStr = explode('.', (string) $totalQuantity); // ['4', '5']
                $quantityInt = floor($totalQuantity); // 4
                $decimalDigits = isset($decimalStr[1]) ? (float) $decimalStr[1] : 0; // 5.0
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitData['quantity']) + $decimalDigits; // (4 * 2) + 5 = 13

                // Calculate returned quantities
                $totalReturnedInPieces = collect($purchaseProductReturns[$pp->purchase_stock_product_id] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return->quantity ?? 0) + ($return->free_quantity ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($saleProducts[$pp->purchase_stock_product_id] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0);
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (float) $saleDecimalStr[1] : 0;
                    return ($saleQtyInt * $unitQty) + $saleQtyDec;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($salesReturnProducts[$pp->purchase_stock_product_id] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return->quantity ?? 0) + ($return->free_quantity ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                $remainingQuantityInPieces = max($totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces, 0);
                $remainingQuantityInUOM = $remainingQuantityInPieces / ($unitData['quantity'] ?? 1);

                // Log calculations
                Log::debug('Quantity Calculation', [
                    'purchase_stock_product_id' => $pp->purchase_stock_product_id,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                    'total_quantity' => $totalQuantity,
                    'measure_unit_quantity' => $unitData['quantity'],
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM
                ]);

                return (object) array_merge((array) $pp, [
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM,
                ]);
            })->filter(fn($pp) => $pp->remaining_quantity_in_pieces > 0);

            // Group by product_id for aggregation
            $products = $purchaseProducts->groupBy('product_id')->map(function ($group) use ($companyId, $measureUnitsCalc, $fieldValues, $saleReturnFieldValues, $soldQuantityIndexes, $returnedQuantityIndexes) {
                $first = $group->first();

                // Aggregate quantities
                $purchasedQuantity = $group->sum('total_purchase_quantity_in_pieces');
                $returnQuantity = $group->sum('total_returned_in_pieces');
                $saleQuantity = $group->sum('total_sold_in_pieces');
                $salesReturnQuantity = $group->sum('total_sale_returns_in_pieces');
                $availableQuantity = max($purchasedQuantity - $returnQuantity - $saleQuantity + $salesReturnQuantity, 0);

                // Fetch product metadata
                $product = Product::where('id', $first->product_id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();



                $originalProductPrice = Product::where('id', $first->product_id)->value('purchase_rate');

                $purchaseProductsPrice = PurchaseStockProduct::where('product_id', $first->product_id)->orderBy('created_at', 'desc')->pluck('price');
                $latestPrice = $purchaseProductsPrice->first();

                // Get the minimum price
                $minProductPrice = $purchaseProductsPrice->min();

                // Get the average price
                $avgProductPrice = $purchaseProductsPrice->avg();




                $getProductForMeasureUnits = Product::with('productLists')
                    ->where('id', $product->id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();


                if ($getProductForMeasureUnits) {
                    // Step 1: Get measure_unit_id from Product
                    $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

                    // Step 2: Add all measure_unit_ids from ProductList
                    $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

                    // Step 3: Merge and make unique
                    $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();
                } else {
                    echo ('Product not found');
                }

                $measureUnitsForProducts = MeasureUnit::whereIn('id', $allUnitIds)
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


                $productFieldValues = collect();
                $productPurchaseProducts = $group->map(function ($pp) use ($fieldValues, $saleReturnFieldValues, $soldQuantityIndexes, $returnedQuantityIndexes, &$productFieldValues) {
                    $availableUnits = (int) $pp->remaining_quantity_in_pieces;
                    if ($availableUnits > 0 && isset($fieldValues[$pp->purchase_stock_product_id])) {
                        $soldIndexes = $soldQuantityIndexes[$pp->purchase_stock_product_id] ?? [];
                        $returnedIndexes = $returnedQuantityIndexes[$pp->purchase_stock_product_id] ?? [];
                        $excludedIndexes = array_unique(array_merge($soldIndexes, $returnedIndexes));

                        $ppFieldValues = $fieldValues[$pp->purchase_stock_product_id]
                            ->filter(fn($fv) => !in_array($fv->quantity_index, $excludedIndexes))
                            ->groupBy('quantity_index')
                            ->take($availableUnits)
                            ->flatten(1)
                            ->map(fn($fv) => [
                                // 'purchase_id' => $fv->purchase_id,
                                // 'purchase_bill_number' => $fv->purchase_bill_number ?? '',
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'purchase_product_id' => $fv->purchase_product_id ?? null,

                                'stock_product_id' => $fv->stock_product_id ?? null,
                                'stock_adjustment_id' => $fv->stock_adjustment_id ?? null,
                                'stock_transfer_id' => $fv->stock_transfer_id ?? null,
                                'stock_reconciliation_id' => $fv->stock_reconciliation_id ?? null,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name ?? 'N/A',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ])->values();
                        $productFieldValues = $productFieldValues->merge($ppFieldValues);
                    }

                    if ($availableUnits > 0 && isset($saleReturnFieldValues[$pp->purchase_stock_product_id])) {
                        $ppSaleReturnFieldValues = $saleReturnFieldValues[$pp->purchase_stock_product_id]
                            ->groupBy('purchase_stock_product_id')
                            ->take($availableUnits)
                            ->flatten(1)
                            ->map(fn($fv) => [
                                'purchase_id' => null,
                                'purchase_bill_number' => '',
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name ?? 'N/A',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ])->values();
                        $productFieldValues = $productFieldValues->merge($ppSaleReturnFieldValues);
                    }

                    return [
                        'purchase_stock_product_id' => $pp->purchase_stock_product_id,
                        // 'purchase_id' => $pp->purchase_id,
                        // 'purchase_bill_number' => $pp->purchase_bill_number,
                        // 'invoice_date' => $pp->invoice_date,
                        'product_id' => $pp->product_id,
                        'product_name' => $pp->product_name,
                        'product_code' => $pp->product_code,
                        'quantity' => $pp->quantity,
                        'free_quantity' => $pp->free_quantity ?? 0,
                        'price' => $pp->price,
                        'is_vatable' => (bool) $pp->is_vatable,

                        'measure_unit_id' => $pp->measure_unit_id,
                        'measure_unit_name' => $pp->measure_unit_name,
                        'measure_unit_quantity' => $pp->measure_unit_quantity,
                        'remaining_quantity_in_pieces' => $pp->remaining_quantity_in_pieces,
                        'remaining_quantity_in_uom' => $pp->remaining_quantity_in_uom,
                        'return_quantity' => $pp->total_returned_in_pieces,
                        'sale_quantity' => $pp->total_sold_in_pieces,
                        'sales_return_quantity' => $pp->total_sale_returns_in_pieces,
                        'expiry_date' => $pp->expiry_date
                    ];
                })->values()->toArray();

                if (empty($productPurchaseProducts)) {
                    Log::info('No purchase products found', [
                        'product_id' => $first->product_id,
                        'company_id' => $companyId
                    ]);
                    return null;
                }

                return [
                    'product_id' => $first->product_id,
                    'product_name' => $product ? $product->name : $first->product_name,
                    'product_code' => $first->product_code,
                    'original_price' => $originalProductPrice,
                    'min_price' => $minProductPrice,
                    'avg_price' => $avgProductPrice,
                    'latest_price' => $latestPrice,
                    'measure_units_for_products' => $measureUnitsForProducts,
                    'is_vatable' => (bool) $group->max('is_vatable'),
                    'measure_unit_id' => $first->measure_unit_id,
                    'measure_unit_name' => $first->measure_unit_name,
                    'measure_unit_quantity' => $first->measure_unit_quantity,
                    'purchased_quantity' => $purchasedQuantity,
                    'return_quantity' => $returnQuantity,
                    'sale_quantity' => $saleQuantity,
                    'sales_return_quantity' => $salesReturnQuantity,
                    'available_quantity' => $availableQuantity,
                    'expiry_dates' => array_filter($group->pluck('expiry_date')->unique()->toArray()),
                    'field_values' => $productFieldValues->values()->toArray(),
                    'purchase_stock_products' => $productPurchaseProducts
                ];
            })->filter()->values()->toArray();

            if (empty($products)) {
                Log::info('No products with available quantity found', [
                    'company_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products with available quantity found'], 404);
            }

            return response()->json([
                'message' => 'Product details retrieved successfully',
                'data' => $products,
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase product not found', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'inputs' => $request->all(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database query error', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'inputs' => $request->all(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            return response()->json(['error' => 'Database error: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error occurred', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'inputs' => $request->all(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . (config('app.debug') ? $e->getMessage() : 'An unexpected error occurred')], 500);
        } finally {
            DB::disableQueryLog();
        }
    }


}