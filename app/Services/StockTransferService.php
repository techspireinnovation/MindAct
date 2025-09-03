<?php

namespace App\Services;
use App\Models\StockTransfer;
use App\Models\StockTransferDetails;
use Illuminate\Support\Facades\DB;
use App\Models\ProductList;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductReturn;
use App\Models\StockTransferFieldValue;

use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProductReturn;


class StockTransferService
{

    public function getUnavailableQuantityIndices($purchaseProduct, int $companyId): array
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

    public function calculatePieces(float $quantity, float $measureUnitQuantity): float
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



    public function calculatePiecestoReduce(float $quantity, float $toReduce, float $pspMuQty): float
    {
        if ($pspMuQty <= 0) {
            Log::warning('Invalid measure unit quantity', ['measureUnitQuantity' => $pspMuQty]);
            return 0;
        }


        $oldQuantity = floor($quantity);

        $decimalPart = $quantity - $oldQuantity;

        $decimalStr = (string) $decimalPart;
        $decimalPieces = $decimalStr > 0 ? (int) str_replace('.', '', (string) $decimalStr) : 0;

        $oldQuantityInPieces = ($oldQuantity * $pspMuQty) + $decimalPieces;

        $newQuantityInPieces = max(0, $oldQuantityInPieces - $toReduce);

        $regularPiecesInt = floor($newQuantityInPieces / $pspMuQty);
        $regularRemainingPieces = $newQuantityInPieces - ($regularPiecesInt * $pspMuQty);
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularPiecesInt + $regularDecimal;

        return $regularQuantity;


    }

    public function calculateAvailablePieces($purchaseProduct, int $companyId, $measureUnitsCalc): int
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


    // public function flattenFieldValues(array $fieldValues, int $index): array
    // {
    //     $flat = [];
    //     foreach ($fieldValues as $set) {
    //         if (!is_array($set)) {
    //             throw new \Exception("Invalid field_values format at index {$index}.");
    //         }
    //         foreach ($set as $fv) {
    //             if (!isset($fv['product_field_id'], $fv['value'], $fv['quantity_index'], $fv['purchase_stock_product_id'])) {
    //                 throw new \Exception("Missing required field value attributes at index {$index}.");
    //             }
    //             $flat[] = [
    //                 'product_field_id' => $fv['product_field_id'],
    //                 'value' => $fv['value'],
    //                 'quantity_index' => $fv['quantity_index'],
    //                 'quantity_type' => $fv['quantity_type'] ?? 'regular',
    //                 'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
    //             ];
    //         }
    //     }
    //     return $flat;
    // }



    public function flattenFieldValues($fieldValues, $index)
    {
        $flat = [];
        if (!empty($fieldValues)) {
            foreach ($fieldValues as $fieldValueSet) {
                $fieldValueSet = is_array($fieldValueSet) && !isset($fieldValueSet['product_field_id']) ? $fieldValueSet : [$fieldValueSet];
                foreach ($fieldValueSet as $fieldValue) {
                    $flat[] = [
                        'product_field_id' => $fieldValue['product_field_id'] ?? null,
                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                        'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'] ?? null,
                        'purchase_stock_product_field_value_id' => $fieldValue['purchase_stock_product_field_value_id'] ?? null,
                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                        'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                        'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                        'value' => $fieldValue['value'] ?? null,
                        'quantity_index' => $fieldValue['quantity_index'] ?? 0,
                        'quantity_type' => $fieldValue['quantity_type'] ?? 'regular',
                    ];
                }
            }
        }
        \Log::debug('Flattened field values', [
            'index' => $index,
            'flat_field_values' => $flat,
        ]);
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



    private function availablePiecesForSaleUpdate(
        $purchaseProduct,
        float $measureUnitQty,
        int $companyId,
        ?int $ignoreSaleId = null
    ): float {
        // 1) pieces that entered via purchase
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQty);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQty);
        $purchasedPieces = $regularPieces + $freePieces;

        // 2) pieces already returned to supplier
        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns()
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

        // 3) pieces already sold (ignore the sale we are editing)
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

        // 4) pieces returned by customers (adds back to stock)
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
                // ignore returns that belong to the sale we are editing
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

        $available = max(0, $purchasedPieces - $purchaseReturnedPieces - $soldPieces + $customerReturnedPieces);

        Log::debug('Available pieces for sale update', [
            'purchase_product_id' => $purchaseProduct->id,
            'purchased' => $purchasedPieces,
            'purchaseRet' => $purchaseReturnedPieces,
            'sold' => $soldPieces,
            'custReturned' => $customerReturnedPieces,
            'available' => $available,
        ]);

        return $available;
    }

    public function listAvailableStock(string $purchaseType, int $companyId, int $branchId): JsonResponse
    {
        try {
            Log::info('listAvailableStock: Processing', [
                'user_id' => auth()->id(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'purchase_type' => $purchaseType
            ]);

            if (!auth()->check()) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            if (!$companyId || !$branchId) {
                return response()->json([
                    'message' => 'No company ID or branch ID provided or available'
                ], 400);
            }

            $products = $this->getAvailableProductsForSale($purchaseType, $companyId, $branchId);

            return response()->json([
                'message' => 'Available products retrieved successfully',
                'count' => $products->count(),
                'data' => $products
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error listing available products', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'purchase_type' => $purchaseType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to retrieve available products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getAvailableProductsForSale($purchaseType, $companyId, $branchId)
    {
        Log::debug('Fetching available products for sale', ['company_id' => $companyId, 'branch_id' => $branchId]);

        try {
            DB::enableQueryLog();

            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                // ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $products = Product::select(['id', 'name'])
                ->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                        ->orWhereNull('company_id');
                })
                ->whereNull('deleted_at')
                ->get();

            Log::info('Fetched products', ['products' => $products->pluck('name', 'id')]);

            if ($products->isEmpty()) {
                Log::warning('No products found', ['company_id' => $companyId, 'branch_id' => $branchId]);
                return collect([]);
            }

            $productIds = $products->pluck('id')->toArray();

            if (strtolower($purchaseType) === 'capital') {
                Log::warning('Purchase type "Capital" is not allowed', [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'purchase_type' => $purchaseType,
                ]);
                return collect([]);
            }

            $purchaseProducts = PurchaseStockProduct::select('purchase_stock_products.*')
                ->whereIn('purchase_stock_products.product_id', $productIds)
                ->where('purchase_stock_products.company_id', $companyId)
                ->where('purchase_stock_products.branch_id', $branchId)
                ->whereNull('purchase_stock_products.deleted_at')
                ->where('purchase_stock_products.purchase_type', $purchaseType)
                ->with([
                    'purchaseStockProductReturns' => fn($q) => $q
                        ->whereNull('purchase_stock_product_returns.deleted_at')
                        ->where('purchase_stock_product_returns.company_id', $companyId)
                        ->where('purchase_stock_product_returns.branch_id', $branchId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),
                    'saleProducts' => fn($q) => $q
                        ->whereNull('sale_products.deleted_at')
                        ->where('sale_products.company_id', $companyId)
                        // ->where('sale_products.branch_id', $branchId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q
                                ->whereNull('sales_return_products.deleted_at')
                                ->where('sales_return_products.company_id', $companyId)
                                // ->where('sales_return_products.branch_id', $branchId)
                                ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])])
                        ]),
                    'fieldValues' => fn($q) => $q
                        ->whereNull('purchase_stock_product_field_values.deleted_at')
                        ->where('purchase_stock_product_field_values.company_id', $companyId)
                        ->where('purchase_stock_product_field_values.branch_id', $branchId)
                ])
                ->get();

            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', ['company_id' => $companyId, 'branch_id' => $branchId, 'product_ids' => $productIds]);
                return collect([]);
            }

            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                // ->where('branch_id', $branchId)
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
                // ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select(['purchase_return_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseProductReturn::find($fv->purchase_return_product_id);
                    return $returnProduct ? $returnProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $results = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $branchId, $measureUnitsCalc) {
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
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            DB::disableQueryLog();
        }
    }




    public function getAvailableProductByIdOrName($purchaseType, int $companyId, int $branchId, $productId): JsonResponse
    {
        try {

            if (!$productId) {
                return response()->json(['error' => 'Either Product Id ,Product Name or Product Barcode is required'], 422);
            }

            // Fetch product details
            $products = $this->getAvailableProductsDetails($productId, null, $companyId, null, $branchId, $purchaseType);

            return response()->json([
                'message' => !empty($products['data']) ? 'Product details retrieved' : 'No matching product found',
                'data' => $products['data'] ?: []
            ], !empty($products['data']) ? 200 : 200);

        } catch (ModelNotFoundException $e) {
            Log::error('Model not found in getAvailableProductByIdOrName', [
                'product_id' => $productId,

                'company_id' => $companyId,
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'No matching product found', 'data' => []], 200);
        } catch (QueryException $e) {
            Log::error('Database query error in getAvailableProductByIdOrName', [
                'product_id' => $productId,

                'company_id' => $companyId,
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Database query error',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getAvailableProductByIdOrName', [
                'error' => $e->getMessage(),

            ]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null, ?int $responseUnitId = null, ?int $branchId = null, ?string $purchaseType = null): array
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
                // ->where('branch_id', $branchId)
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
                    'branch_id' => $branchId,
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
                ->where('company_id', $companyId)
                // ->where('branch_id', $branchId)
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
                // ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity'])
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });

            $purchaseProducts = PurchaseStockProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with([
                    'purchase' => fn($q) => $q->select(['id', 'company_id', 'purchase_bill_number', 'invoice_date'])
                        ->whereNull('deleted_at')
                        ->where('company_id', $companyId),
                    // ->where('branch_id', $branchId),
                    'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        // ->where('branch_id', $branchId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),
                    'saleProducts' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        // ->where('branch_id', $branchId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q->whereNull('deleted_at')
                                ->where('company_id', $companyId)
                                // ->where('branch_id', $branchId)
                                ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])])
                        ]),
                    'fieldValues' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->with([
                            'productField' => fn($q) => $q->select(['id', 'name', 'company_id'])
                                ->where('company_id', $companyId)
                                // ->where('branch_id', $branchId)
                                ->whereNull('deleted_at')
                        ])
                ])
                ->orderBy('created_at', 'asc')
                ->get();

            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', [
                    'product_ids' => $productIds,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            // Fetch quantity indexes
            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                // ->where('branch_id', $branchId)
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
                // ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select(['purchase_return_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseProductReturn::find($fv->purchase_return_product_id);
                    return $returnProduct ? $returnProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());



            $returnedstockIndexes = StockTransferFieldValue::whereIn('purchase_stock_product_id', $purchaseProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')

                ->select(['purchase_stock_product_id', 'quantity_index'])
                ->get()
                ->groupBy('purchase_stock_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            // Process 

            $result = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $returnedstockIndexes, $companyId, $branchId, $measureUnitsCalc, $measureUnitsUsed, $latestSoldPrice, $minPrice, $avgPrice, $retailSalePrice, $primaryMeasureUnitQuantity, $primarayMeasureUnitId, ) {
                $allFieldValues = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->flatMap(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $returnedstockIndexes) {
                        return $pp->fieldValues->filter(function ($fv) use ($soldQuantityIndexes, $returnedQuantityIndexes, $returnedstockIndexes, $pp) {
                            $excludedIndexes = array_unique(array_merge(
                                $soldQuantityIndexes[$pp->id] ?? [],
                                $returnedQuantityIndexes[$pp->id] ?? [],
                                $returnedstockIndexes[$pp->id] ?? []
                            ));

                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_stock_product_field_value_id' => $fv->id,
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField->name ?? null,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values();
                    })->toArray();

                $productPurchaseProducts = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->map(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $returnedstockIndexes, $companyId, $branchId, $measureUnitsCalc) {
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
                        $fieldValues = $pp->fieldValues->filter(function ($fv) use ($soldQuantityIndexes, $returnedQuantityIndexes, $returnedstockIndexes, $pp) {
                            $excludedIndexes = array_unique(array_merge(
                                $soldQuantityIndexes[$pp->id] ?? [],
                                $returnedQuantityIndexes[$pp->id] ?? [],
                                $returnedstockIndexes[$pp->id] ?? []
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
                    // ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->pluck('price');
                $lastSalesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    // ->where('branch_id', $branchId)
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


}
