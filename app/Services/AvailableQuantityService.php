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
use App\Models\Purchase;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesReturnProduct;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductReturn;
use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\StockTransferFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProductReturn;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Validation\Rule;


class AvailableQuantityService
{
     public static function getPurchaseAvailableByBillNumber(Request $request,$purchaseBillNo): JsonResponse
    {
        try {
            // Validate input parameters
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'branch_id' => 'required|integer|exists:branches,id',
                'purchase_bill_number' => 'nullable|string|max:255',
               
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }

            

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $purchaseBillNumber = $purchaseBillNo;
            $purchaseNumber = $request->input('purchase_number');

            Log::debug('Input parameters', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
            ]);

            // Enable query logging for debugging
            DB::enableQueryLog();

            // Query purchase with related data
            $purchaseQuery = Purchase::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->when($purchaseBillNumber, fn($q) => $q->where('purchase_bill_number', $purchaseBillNumber))
                ->when($purchaseNumber, fn($q) => $q->where('purchase_number', $purchaseNumber))
                ->with([
                    'purchaseStockProducts' => function ($query) use ($companyId, $branchId) {
                        $query->whereNull('deleted_at')
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->with([
                                'measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at'),
                                'fieldValues.productField' => fn($q) => $q->select('id', 'name')->whereNull('deleted_at'),
                                'purchaseStockProductReturns' => fn($subQuery) => $subQuery->whereNull('deleted_at')
                                    ->where('company_id', $companyId)
                                    ->where('branch_id', $branchId)
                                    ->with(['measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at')]),
                                'saleProducts' => fn($subQuery) => $subQuery->whereNull('deleted_at')
                                    ->where('company_id', $companyId)
                                    ->with([
                                        'measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at'),
                                        'saleProductReturns' => fn($subSubQuery) => $subSubQuery->whereNull('deleted_at')
                                            ->where('company_id', $companyId)
                                            ->with(['measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at')]),
                                    ]),
                            ]);
                    }
                ]);

            $purchase = $purchaseQuery->first();

            if (!$purchase) {
                Log::info('Purchase not found', [
                    'company_id' => $companyId,
                    'purchase_bill_number' => $purchaseBillNumber,
                    'purchase_number' => $purchaseNumber,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'Purchase not found'], 404);
            }

            if (empty($purchase->purchaseStockProducts)) {
                Log::info('No purchase products found', [
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'No available products for this purchase'], 404);
            }

            // Prepare response data
            $purchaseData = $purchase->toArray();
            $payment = $purchase->payment ?? [];

            $purchaseData['payment'] = [
                'cash' => $payment['cash'] ?? null,
                'credit' => $payment['credit'] ?? null,
                'bank' => $payment['bank'] ?? null,
            ];

            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $purchaseProducts = collect($purchaseData['purchase_stock_products'])->filter(function ($product) use ($companyId, $measureUnitsCalc) {
                Log::debug('Raw purchase product data', [
                    'purchase_stock_product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $product['quantity'] ?? 0,
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'measure_unit_id' => $product['measure_unit_id'] ?? null,
                ]);


                // Ensure measureUnit is valid
                $measureUnitId = $product['measure_unit_id'] ?? null;
                $unitData = isset($measureUnitsCalc[$measureUnitId]) ? [
                    'id' => $measureUnitsCalc[$measureUnitId]->id,
                    'name' => $measureUnitsCalc[$measureUnitId]->name,
                    'quantity' => $measureUnitsCalc[$measureUnitId]->quantity ?? 1
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1
                ];

                Log::debug('Processing product measure unit', [
                    'purchase_product_id' => $product['id'] ?? 'unknown',
                    'measure_unit' => $unitData,
                ]);

                // Calculate total quantity in pieces
                $totalQuantity = ((float) ($product['quantity'] ?? 0)) + ((float) ($product['free_quantity'] ?? 0));
                $unitQuantity = $unitData['quantity'] ?? 1;
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (int) str_replace('.', '', $decimalStr[1]) : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;

                Log::debug('Total purchase quantity calculation', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $product['quantity'] ?? 0,
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'total_quantity' => $totalQuantity,
                    'unit_quantity' => $unitQuantity,
                    'quantity_int' => $quantityInt,
                    'decimal_digits' => $decimalDigits,
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                ]);

                // Calculate returned quantities
                $totalReturnedInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $measureUnitQuantity = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $quantity = (float) ($return['quantity'] ?? 0);
                    $freeQuantity = (float) ($return['free_quantity'] ?? 0);

                    // Calculate pieces for quantity
                    $integerPart = floor($quantity);
                    $decimalPart = $quantity - $integerPart;
                    $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
                    $quantityPieces = ($integerPart * $measureUnitQuantity) + $decimalPieces;

                    // Calculate pieces for free_quantity
                    $freeIntegerPart = floor($freeQuantity);
                    $freeDecimalPart = $freeQuantity - $freeIntegerPart;
                    $freeDecimalPieces = $freeDecimalPart > 0 ? (int) str_replace('.', '', (string) $freeDecimalPart) : 0;
                    $freeQuantityPieces = ($freeIntegerPart * $measureUnitQuantity) + $freeDecimalPieces;

                    $returnTotal = $quantityPieces + $freeQuantityPieces;

                    Log::debug('Return quantity calculation', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'sum_quantity' => $quantity + $freeQuantity,
                        'measure_unit_id' => $unitId,
                        'measure_unit_quantity' => $measureUnitQuantity,
                        'quantity_pieces' => $quantityPieces,
                        'free_quantity_pieces' => $freeQuantityPieces,
                        'total_returned_pieces' => $returnTotal,
                    ]);

                    return $returnTotal;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($product['sale_products'] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ((float) ($sale['quantity'] ?? 0)) + ((float) ($sale['free_quantity'] ?? 0));
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (int) str_replace('.', '', $saleDecimalStr[1]) : 0;
                    $soldPieces = ($saleQtyInt * $unitQty) + $saleQtyDec;

                    Log::debug('Sale quantity calculation', [
                        'sale_id' => $sale['id'] ?? 'unknown',
                        'quantity' => $sale['quantity'] ?? 0,
                        'free_quantity' => $sale['free_quantity'] ?? 0,
                        'total_quantity' => $saleTotalQty,
                        'unit_quantity' => $unitQty,
                        'sale_qty_int' => $saleQtyInt,
                        'sale_qty_dec' => $saleQtyDec,
                        'sold_pieces' => $soldPieces,
                    ]);

                    return $soldPieces;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($product['sale_products'] ?? [])->flatMap(function ($sale) {
                    return $sale['sale_product_returns'] ?? [];
                })->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ((float) ($return['quantity'] ?? 0)) + ((float) ($return['free_quantity'] ?? 0));
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $saleReturnPieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Sale return quantity calculation', [
                        'sale_return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $return['quantity'] ?? 0,
                        'free_quantity' => $return['free_quantity'] ?? 0,
                        'total_quantity' => $retTotalQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'sale_return_pieces' => $saleReturnPieces,
                    ]);

                    return $saleReturnPieces;
                });

                $availableQuantityInPieces = $totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces;

                Log::debug('Available quantity calculation', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'available_quantity_in_pieces' => $availableQuantityInPieces,
                ]);

                return $availableQuantityInPieces > 0;
            })->map(function ($product) use ($companyId, $branchId, $measureUnitsCalc) {
                Log::debug('Raw purchase product data in map', [
                    'purchase_product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $product['quantity'] ?? 0,
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'measure_unit_id' => $product['measure_unit_id'] ?? null,
                ]);

                // Ensure measureUnit is valid
                $measureUnitId = $product['measure_unit_id'] ?? null;
                $unitData = isset($measureUnitsCalc[$measureUnitId]) ? [
                    'id' => $measureUnitsCalc[$measureUnitId]->id,
                    'name' => $measureUnitsCalc[$measureUnitId]->name,
                    'quantity' => $measureUnitsCalc[$measureUnitId]->quantity ?? 1
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1
                ];

                Log::debug('Processing product measure unit in map', [
                    'purchase_product_id' => $product['id'] ?? 'unknown',
                    'measure_unit' => $unitData,
                ]);

                // Calculate quantities
                $unitQuantity = $unitData['quantity'] ?? 1;
                $quantity = (float) ($product['quantity'] ?? 0);
                $decimalStrforRegularQuantity = explode('.', (string) $quantity);
                $regularQuantityInt = floor($quantity);
                $regularDecimalDigits = isset($decimalStrforRegularQuantity[1]) ? (int) str_replace('.', '', $decimalStrforRegularQuantity[1]) : 0;
                $totalRegularQuantity = ($regularQuantityInt * $unitQuantity) + $regularDecimalDigits;
                $freeQuantity = (float) ($product['free_quantity'] ?? 0);
                $decimalStrforFreeQuantity = explode('.', (string) $freeQuantity);
                $freeQuantityInt = floor($freeQuantity);
                $freeDecimalDigits = isset($decimalStrforFreeQuantity[1]) ? (int) str_replace('.', '', $decimalStrforFreeQuantity[1]) : 0;
                $totalFreeQuantity = ($freeQuantityInt * $unitQuantity) + $freeDecimalDigits;

                // For Total Remaining
                $totalQuantity = $quantity + $freeQuantity;
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (int) str_replace('.', '', $decimalStr[1]) : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;
                $totalPurchaseQuantityInUOM = $totalQuantity;

                Log::debug('Purchase quantity in map', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                    'total_quantity' => $totalQuantity,
                    'unit_quantity' => $unitQuantity,
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_purchase_quantity_in_uom' => $totalPurchaseQuantityInUOM,
                ]);

                // Calculate returned quantities
                $totalReturnedInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $quantity = (float) ($return['quantity'] ?? 0);
                    $freeQuantity = (float) ($return['free_quantity'] ?? 0);

                    // Calculate pieces for quantity
                    $integerPart = floor($quantity);
                    $decimalPart = $quantity - $integerPart;
                    $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
                    $quantityPieces = ($integerPart * $unitQty) + $decimalPieces;

                    // Calculate pieces for free_quantity
                    $freeIntegerPart = floor($freeQuantity);
                    $freeDecimalPart = $freeQuantity - $freeIntegerPart;
                    $freeDecimalPieces = $freeDecimalPart > 0 ? (int) str_replace('.', '', (string) $freeDecimalPart) : 0;
                    $freeQuantityPieces = ($freeIntegerPart * $unitQty) + $freeDecimalPieces;

                    $totalReturned = $quantityPieces + $freeQuantityPieces;

                    Log::debug('Total returned in map', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'sum_quantity' => $quantity + $freeQuantity,
                        'unit_quantity' => $unitQty,
                        'quantity_integer_part' => $integerPart,
                        'quantity_decimal_part' => $decimalPart,
                        'quantity_decimal_pieces' => $decimalPieces,
                        'quantity_pieces' => $quantityPieces,
                        'free_integer_part' => $freeIntegerPart,
                        'free_decimal_part' => $freeDecimalPart,
                        'free_decimal_pieces' => $freeDecimalPieces,
                        'free_quantity_pieces' => $freeQuantityPieces,
                        'total_returned' => $totalReturned,
                    ]);

                    return $totalReturned;
                });

                $returnedRegularInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retQty = (float) ($return['quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retQty);
                    $retQtyInt = floor($retQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $returnedPieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Returned regular quantity in map', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $retQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'returned_pieces' => $returnedPieces,
                    ]);

                    return $returnedPieces;
                });

                $returnedFreeInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retFreeQty = (float) ($return['free_quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retFreeQty);
                    $retQtyInt = floor($retFreeQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $returnedFreePieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Returned free quantity in map', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'free_quantity' => $retFreeQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'returned_free_pieces' => $returnedFreePieces,
                    ]);

                    return $returnedFreePieces;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($product['sale_products'] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ((float) ($sale['quantity'] ?? 0)) + ((float) ($sale['free_quantity'] ?? 0));
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (int) str_replace('.', '', $saleDecimalStr[1]) : 0;
                    $soldPieces = ($saleQtyInt * $unitQty) + $saleQtyDec;

                    Log::debug('Sale quantity calculation in map', [
                        'sale_id' => $sale['id'] ?? 'unknown',
                        'quantity' => $sale['quantity'] ?? 0,
                        'free_quantity' => $sale['free_quantity'] ?? 0,
                        'total_quantity' => $saleTotalQty,
                        'unit_quantity' => $unitQty,
                        'sale_qty_int' => $saleQtyInt,
                        'sale_qty_dec' => $saleQtyDec,
                        'sold_pieces' => $soldPieces,
                    ]);

                    return $soldPieces;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($product['sale_products'] ?? [])->flatMap(function ($sale) {
                    return $sale['sale_product_returns'] ?? [];
                })->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ((float) ($return['quantity'] ?? 0)) + ((float) ($return['free_quantity'] ?? 0));
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $saleReturnPieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Sale return quantity calculation in map', [
                        'sale_return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $return['quantity'] ?? 0,
                        'free_quantity' => $return['free_quantity'] ?? 0,
                        'total_quantity' => $retTotalQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'sale_return_pieces' => $saleReturnPieces,
                    ]);

                    return $saleReturnPieces;
                });

                // Adjust remaining quantities
                $remainingRegularQuantity = max($totalRegularQuantity - $returnedRegularInPieces, 0);
                $remainingFreeQuantity = max($totalFreeQuantity - $returnedFreeInPieces, 0);
                $remainingQuantityInPieces = max($totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces, 0);
                $remainingQuantityInUOM = $remainingQuantityInPieces / ($unitData['quantity'] ?? 1);

                Log::debug('Final remaining quantity calculation', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM,
                ]);

                // Process field values
                $unavailableQuantityIndices = [];
                $groupedFieldValues = [];

                // Handle purchase returns
                if (!empty($product['purchase_stock_product_returns'])) {
                    $returnIds = array_column($product['purchase_stock_product_returns'], 'id');
                    $unavailableQuantityIndices = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->pluck('quantity_index')
                        ->toArray();
                }

                // Handle sold units
                $saleProductIds = array_column($product['sale_products'] ?? [], 'id');
                $soldQuantityIndices = SalesProductFieldValue::whereIn('sale_product_id', $saleProductIds)
                    ->whereNull('deleted_at')
                    ->where('company_id', $companyId)
                    ->pluck('quantity_index')
                    ->toArray();
                $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldQuantityIndices);

                // Handle stock transfers
                $stockTransferQuantityIndices = StockTransferFieldValue::where('purchase_stock_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->pluck('quantity_index')
                    ->toArray();
                $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $stockTransferQuantityIndices);
                // Handle sales returns
                $saleReturnFieldValues = [];
                $saleReturnedIndices = [];
                if ($totalSaleReturnsInPieces > 0) {
                    $saleReturnFieldValues = SaleReturnProductFieldValue::whereIn(
                        'sale_return_product_id',
                        SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                            ->whereNull('deleted_at')
                            ->where('company_id', $companyId)
                            ->pluck('id')
                    )
                        ->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with(['productField' => fn($q) => $q->select('id', 'name')])
                        ->get()
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                    'quantity_index' => $field->quantity_index,
                                    'quantity_type' => $field->quantity_type,
                                    'name' => $field->productField->name ?? 'N/A',
                                ];
                            })->toArray();
                        })->toArray();

                    $saleReturnedIndices = array_keys($saleReturnFieldValues);
                    $unavailableQuantityIndices = array_diff(array_unique($unavailableQuantityIndices), $saleReturnedIndices);
                }

                // Group available field values
                if (!empty($product['field_values'])) {
                    foreach ($product['field_values'] as $fieldValue) {
                        $quantityIndex = $fieldValue['quantity_index'] ?? 0;
                        if (in_array($quantityIndex, $unavailableQuantityIndices)) {
                            continue;
                        }
                        $groupedFieldValues[$quantityIndex][] = [
                            'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'] ?? null,
                            'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                            'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                            'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                            'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                            'stock_transfer_id' => $fieldValue['stock_transfer_id'] ?? null,
                            'product_field_id' => $fieldValue['product_field_id'] ?? null,
                            'name' => $fieldValue['product_field']['name'] ?? 'N/A',
                            'quantity_index' => $quantityIndex,
                            'quantity_type' => $fieldValue['quantity_type'] ?? null,
                            'value' => $fieldValue['value'] ?? null,
                        ];
                    }
                }

                // Override with sales return field values
                if (!empty($saleReturnedIndices)) {
                    foreach ($saleReturnedIndices as $quantityIndex) {
                        if (isset($saleReturnFieldValues[$quantityIndex])) {
                            $groupedFieldValues[$quantityIndex] = $saleReturnFieldValues[$quantityIndex];
                        }
                    }
                }

                // Limit to available quantity and filter out empty arrays
                $groupedFieldValues = array_slice($groupedFieldValues, 0, (int) $remainingQuantityInPieces, true);
                $groupedFieldValues = array_filter($groupedFieldValues, fn($value) => !empty($value));

                Log::debug('Field values processing', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'grouped_field_values' => $groupedFieldValues,
                    'unavailable_quantity_indices' => $unavailableQuantityIndices,
                    'sale_returned_indices' => $saleReturnedIndices,
                ]);

                $getOriginalPrice = Product::where('id', $product['product_id'])->pluck('purchase_rate')->first();

                $getProductForMeasureUnits = Product::with('productLists')
                    ->where('id', $product['product_id'])
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();

                $allUnitIds = collect([]);
                if ($getProductForMeasureUnits) {
                    // Step 1: Get measure_unit_id from Product
                    $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

                    // Step 2: Add all measure_unit_ids from ProductList
                    $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

                    // Step 3: Merge and make unique
                    $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();
                } else {
                    Log::warning('Product not found for measure units', [
                        'product_id' => $product['product_id'] ?? 'unknown',
                        'company_id' => $companyId,
                    ]);
                }

                $measureUnitsForProducts = MeasureUnit::whereIn('id', $allUnitIds)
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

                Log::debug('Measure units for product', [
                    'product_id' => $product['product_id'] ?? 'unknown',
                    'unit_ids' => $allUnitIds->toArray(),
                    'measure_units' => $measureUnitsForProducts->toArray(),
                ]);

                // Prepare product data, filtering out invalid values
                $productData = array_filter([
                    'purchase_stock_product_id' => $product['id'] ?? null,
                    'purchase_product_id' => $product['purchase_product_id'] ?? null,
                    'stock_transfer_id' => $product['stock_transfer_id'] ?? null,
                    'stock_product_id' => $product['stock_product_id'] ?? null,
                    'stock_reconciliation_id' => $product['stock_reconciliation_id'] ?? null,
                    'stock_adjustment_id' => $product['stock_adjustment_id'] ?? null,
                    'purchase_id' => $product['purchase_id'] ?? null,
                    'product_id' => $product['product_id'] ?? null,
                    'product_name' => $product['product_name'] ?? null,
                    'product_code' => $product['product_code'] ?? null,
                    'quantity' => $quantity,
                    'measure_unit_id' => $unitData['id'] ?? 0,
                    'measure_unit_quantity' => $unitData['quantity'] ?? 1,
                    'measure_unit_name' => $unitData['name'] ?? 'null',
                    'amount' => $product['amount'] ?? 0,
                    'free_quantity' => $freeQuantity,
                    'purchased_quantity' => $totalPurchaseQuantityInPieces,
                    'returned_quantity' => $totalReturnedInPieces,
                    'sold_quantity' => $totalSoldInPieces,
                    'sale_returned_quantity' => $totalSaleReturnsInPieces,
                    'measure_units_for_products' => $measureUnitsForProducts->toArray(),
                    'original_price' => $getOriginalPrice ?? 0,
                    'remaining_quantity' => $remainingQuantityInPieces,
                    'regular_remaining_quantity' => $remainingRegularQuantity,
                    'free_remaining_quantity' => $remainingFreeQuantity,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM,
                    'price' => $product['price'] ?? 0,
                    'is_vatable' => (bool) ($product['is_vatable'] ?? false),
                    'expiry_date' => $product['expiry_date'] ?? null,
                    'field_values' => array_values($groupedFieldValues),
                ], function ($value) {
                    return !is_null($value) && (!is_array($value) || !empty($value));
                });

                Log::debug('Final product data', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'product_data' => $productData,
                ]);

                return $productData;
            })->values()->toArray();

            if (empty($purchaseProducts)) {
                Log::info('No products with available quantity found', [
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'No products with available quantity found'], 404);
            }

            $purchaseData['purchase_stock_products'] = $purchaseProducts;

            return response()->json([
                'message' => 'Purchase details retrieved successfully',
                'data' => $purchaseData,
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase not found', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog(),
            ]);
            return response()->json(['error' => 'Purchase not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database query error', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog(),
            ]);
            return response()->json(['error' => 'Database error: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error occurred', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } finally {
            DB::disableQueryLog();
        }
    }


     public static function getAvailableQuantityByPurchaseStockReturnId(Request $request, $purchaseBillNo): JsonResponse
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

            // if (!$request->hasAny(['product_code', 'product_name', 'barcode', 'purchase_bill_number'])) {
            //     Log::warning('No valid search parameters provided', ['request' => $request->all()]);
            //     return response()->json(['error' => 'At least one of product_code, product_name, barcode, or purchase_bill_number is required'], 422);
            // }

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $productCode = $request->input('product_code');
            $productName = trim(strtolower($request->input('product_name')));
            $barcode = $request->input('barcode');
            $purchaseBillNumber = $purchaseBillNo;

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

            $productId = $purchaseProducts->pluck('product_id')->unique()->toArray();


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