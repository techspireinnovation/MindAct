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
use App\Models\ProductList;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesReturnProduct;
use App\Models\PurchaseStockProductReturn;
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
    public static function getPurchaseAvailableByBillNumber(Request $request, $purchaseBillNo): JsonResponse
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


    public static function getAvailableProductDetailsById(Request $request,$productID): JsonResponse
    {
        try {
           

            $productId = $productID;
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
            $products = static::getAvailableProductsDetails($productId, $productName, $companyId, $branchId, $responseUnitId);

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
    public static function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null, ?int $branchId = null, ?int $responseUnitId = null): array
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

            // Fetch quantity indexes
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
            $result = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $salesReturnQuantityIndexes, $transferQuantityIndexes, $companyId, $branchId, $measureUnitsCalc, $measureUnitsUsed, $latestSoldPrice, $minPrice, $avgPrice, $retailSalePrice, $primaryMeasureUnitQuantity, $primarayMeasureUnitId) {
                $allFieldValues = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->flatMap(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $salesReturnQuantityIndexes, $transferQuantityIndexes, ) {
                        // Only exclude sold indices that weren't returned
                        $netSoldIndexes = array_diff($soldQuantityIndexes[$pp->id] ?? [], $salesReturnQuantityIndexes[$pp->id] ?? []);
                        $excludedIndexes = array_unique(array_merge(
                            $netSoldIndexes,
                            $returnedQuantityIndexes[$pp->id]
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
                    ->map(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $salesReturnQuantityIndexes, $companyId, $branchId, $measureUnitsCalc) {
                        // Calculate purchased pieces
                        $purchasedPieces = static::calculatePieces(
                            ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                            measureUnitQuantity: isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1
                        );

                        // Calculate return pieces, capped at purchased pieces
                        $returnPieces = $pp->purchaseStockProductReturns->reduce(
                            fn($carry, $return) => $carry + static::calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $returnPieces = min($returnPieces, $purchasedPieces);

                        // Calculate sale and sales return pieces
                        $salePieces = $pp->saleProducts->reduce(
                            fn($carry, $sale) => $carry + static::calculatePieces(
                                ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                                isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $salesReturnPieces = $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                            fn($carry, $return) => $carry + static::calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );

                        // Calculate available pieces
                        $availablePieces = static::calculateAvailablePieces($pp, $companyId, $branchId, $measureUnitsCalc);

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
                            $returnedQuantityIndexes[$pp->id] ?? []
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
                    fn($pp) => static::calculatePieces(
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
    public static function calculatePieces(float $quantity, float $measureUnitQuantity): float
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

    public static function calculateAvailablePieces($purchaseProduct, int $companyId, int $branchId, $measureUnitsCalc): int
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
            $unavailableIndices = static::getUnavailableQuantityIndices($purchaseProduct, $companyId, $branchId);
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
        $regularPieces = static::calculatePieces($purchaseProduct->quantity ?? 0, $purchaseMeasureUnitQuantity);
        $freePieces = static::calculatePieces($purchaseProduct->free_quantity ?? 0, $purchaseMeasureUnitQuantity);
        $totalPurchasedPieces = $regularPieces + $freePieces;

        $purchaseReturnedPieces = $purchaseProduct->purchaseStockProductReturns->reduce(
            function ($carry, $return) use ($measureUnitsCalc) {
                $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                return $carry + static::calculatePieces(
                    ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                    $returnMeasureUnitQuantity
                );
            },
            0
        );

        $soldPieces = $purchaseProduct->saleProducts->reduce(
            function ($carry, $sale) use ($measureUnitsCalc) {
                $saleMeasureUnitQuantity = isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1;
                return $carry + static::calculatePieces(
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
                    return $carry + static::calculatePieces(
                        ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                        $returnMeasureUnitQuantity
                    );
                },
                0
            );

        $availablePieces = $totalPurchasedPieces - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces;

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

     public static function getUnavailableQuantityIndices($purchaseProduct, int $companyId, int $branchId): array
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

        $unavailableIndices = array_unique(array_merge($soldIndices, $returnedIndices));

        Log::debug('Unavailable quantity indices', [
            'purchase_stock_product_id' => $purchaseProduct->id,
            'sold_indices' => $soldIndices,
            'returned_indices' => $returnedIndices,
            'unavailable_indices' => $unavailableIndices
        ]);

        return $unavailableIndices;
    }


}