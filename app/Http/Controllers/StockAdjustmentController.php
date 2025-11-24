<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Services\AvailableQuantityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\StockAdjustmentProduct;
use App\Models\SaleProduct;
use App\Models\SalesReturnProduct;
use App\Models\PurchaseStockProductReturn;
use App\Models\StockTransferFieldValue;
use App\Models\SaleReturnProductFieldValue;


use App\Models\SalesProductFieldValue;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\StockAdjustmentProductFieldValue;
use App\Models\PurchaseStockProduct;
use App\Models\ProductList;
use App\Models\StockAdjustedFieldValue;
use App\Models\StockAdjusted;
use App\Models\MeasureUnit;
use App\Models\PurchaseStockProductFieldValue;
use App\Models\ProductField;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockAdjustment::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
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

        $available = max(0, $purchasedPieces - $purchaseReturnedPieces - $soldPieces + $customerReturnedPieces);

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
                    'value_type' => $fv['value_type'] ?? 'selected',
                ];
            }
        }
        return $flat;
    }






    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Log the input request for debugging
            Log::info('Update request product_details:', $request->product_details);

            // Validation rules
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_adjustments')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id);
                        }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                'branch_id' => 'nullable|integer|exists:branches,id',
                'remarks' => 'nullable|string|max:255',
                'reasons' => 'nullable|string|max:255',
                'product_details' => 'required|array',
                'product_details.*.id' => 'nullable|integer|exists:stock_adjustment_products,id',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.adjusted_type' => 'required_with:product_details|in:add,subtract',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.measure_unit_id' => 'required_with:product_details|integer|exists:measure_units,id',
                // 'product_details.*.branch_id' => 'nullable|integer|exists:branches,id',
                'product_details.*.purchase_type' => 'nullable|string',
                'product_details.*.product_code' => 'nullable|string|max:255',
                'product_details.*.hs_code' => 'nullable|string|max:255',
                'product_details.*.mfd' => 'nullable|string|max:255',
                'product_details.*.quantity' => 'nullable|numeric',
                'product_details.*.free_quantity' => 'nullable|numeric',
                'product_details.*.expiry_date' => 'nullable|string|max:255',
                'product_details.*.price' => 'nullable|numeric',
                'product_details.*.discount_percent' => 'nullable|numeric',
                'product_details.*.discount_amount' => 'nullable|numeric',
                'product_details.*.amount' => 'nullable|numeric',
                'product_details.*.is_vatable' => 'nullable|boolean',
                'product_details.*.field_values' => 'nullable|array',
                'product_details.*.field_values.*.*.product_field_id' => 'required_with:product_details.*.field_values|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.value' => 'required_with:product_details.*.field_values|string|max:255',
                'product_details.*.field_values.*.*.quantity_type' => 'nullable|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_with:product_details.*.field_values|numeric|min:0',
                'product_details.*.field_values.*.*.value_type' => 'required_with:product_details.*.field_values|string|in:selected,unselected',
                'company_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['company_id'] = $request->company_id;
            $validated['branch_id'] = $request->branch_id;

            $stockAdjustment = DB::transaction(function () use ($validated, $id, $request) {
                $stockAdjustment = StockAdjustment::findOrFail($id);

                // Update main StockAdjustment record
                $stockAdjustment->update([
                    'reference_no' => $validated['reference_no'],
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'document_number' => $validated['document_number'] ?? null,
                    'location_id' => $validated['location_id'],
                    'remarks' => $validated['remarks'] ?? null,
                    'reasons' => $validated['reasons'] ?? null,
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
                ]);

                // Get existing StockAdjustmentProduct records
                $existingSAPs = StockAdjustmentProduct::where('stock_adjustment_id', $stockAdjustment->id)
                    ->get()
                    ->keyBy('id');

                // Delete all existing StockAdjusted and PurchaseStockProduct records (and their field values)
                StockAdjustedFieldValue::where('stock_adjustment_id', $stockAdjustment->id)->delete();
                StockAdjusted::where('stock_adjustment_id', $stockAdjustment->id)->delete();
                PurchaseStockProductFieldValue::where('stock_adjustment_id', $stockAdjustment->id)->delete();
                PurchaseStockProduct::where('stock_adjustment_id', $stockAdjustment->id)
                    ->whereNull('purchase_id')
                    ->delete();

                $providedSAPIds = [];

                $productDetails = $validated['product_details'];

                foreach ($productDetails as $detail) {
                    // Ensure field_values is empty if not provided
                    if (!isset($detail['field_values'])) {
                        $detail['field_values'] = [];
                    }

                    $sapId = $detail['id'] ?? null;
                    $productId = $detail['product_id'];
                    $branchId = $detail['branch_id'] ?? $request->branch_id ?? null;

                    $commonData = [
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'] ?? null,
                        'hs_code' => $detail['hs_code'] ?? null,
                        'mfd' => $detail['mfd'] ?? null,
                        'expiry_date' => $detail['expiry_date'] ?? null,
                        'free_quantity' => $detail['free_quantity'] ?? 0,
                        'price' => $detail['price'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'amount' => $detail['amount'] ?? 0,
                        'is_vatable' => $detail['is_vatable'] ?? null,
                        'measure_unit_id' => $detail['measure_unit_id'],
                    ];

                    // Handle StockAdjustmentProduct
                    if ($sapId && ($existingSAP = $existingSAPs->get($sapId))) {
                        $providedSAPIds[] = $sapId;
                        $existingSAP->update(array_merge($commonData, [
                            'purchase_stock_product_id' => null,
                            'customer_id' => null,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $branchId,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'product_id' => $productId,
                            'current_stock' => $detail['current_stock'],
                            'actual_stock' => $detail['actual_stock'],
                            'diff_stock' => $detail['diff_stock'],
                            'quantity' => $detail['diff_stock'],
                        ]));
                        $stockAdjustmentProduct = $existingSAP;
                    } else {
                        // Create new if ID not provided or not found
                        $stockAdjustmentProduct = StockAdjustmentProduct::create(array_merge($commonData, [
                            'stock_adjustment_id' => $stockAdjustment->id,
                            'purchase_stock_product_id' => null,
                            'customer_id' => null,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $branchId,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'product_id' => $productId,
                            'current_stock' => $detail['current_stock'],
                            'actual_stock' => $detail['actual_stock'],
                            'diff_stock' => $detail['diff_stock'],
                            'quantity' => $detail['diff_stock'],
                        ]));
                        if ($sapId) {
                            Log::warning('StockAdjustmentProduct ID provided but not found, created new', ['sap_id' => $sapId]);
                            $providedSAPIds[] = $stockAdjustmentProduct->id;
                        }
                    }

                    // Create new StockAdjusted record (mimicking store logic)
                    $stockAdjusted = StockAdjusted::create(array_merge($commonData, [
                        'stock_adjustment_id' => $stockAdjustment->id,
                        'purchase_stock_product_id' => null,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $branchId,
                        'product_id' => $productId,
                        'adjusted_type' => $detail['adjusted_type'],
                        'quantity' => $detail['diff_stock'],
                        'diff_stock' => $detail['diff_stock'],
                    ]));

                    // Create new PurchaseStockProduct for 'add' adjustments
                    $purchaseStockProduct = null;
                    if ($detail['adjusted_type'] === 'add') {
                        $purchaseStockProduct = PurchaseStockProduct::create(array_merge($commonData, [
                            'customer_id' => null,
                            'company_id' => $validated['company_id'],
                            'stock_adjustment_id' => $stockAdjustment->id,
                            'branch_id' => $branchId,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'product_id' => $productId,
                            'quantity' => $detail['diff_stock'],
                        ]));
                    }

                    // Process field values
                    if (!empty($detail['field_values'])) {
                        Log::info('Processing field_values for product_id: ' . $detail['product_id'], $detail['field_values']);

                        // Delete existing field values for StockAdjustmentProduct
                        StockAdjustmentProductFieldValue::where('stock_adjustment_product_id', $stockAdjustmentProduct->id)->delete();

                        foreach ($detail['field_values'] as $fieldValueGroup) {
                            foreach ($fieldValueGroup as $fieldValue) {
                                // Create StockAdjustmentProductFieldValue (for 'selected')
                                if ($fieldValue['value_type'] === 'selected') {
                                    StockAdjustmentProductFieldValue::create([
                                        'stock_adjustment_product_id' => $stockAdjustmentProduct->id,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'] ?? null,
                                        'company_id' => $validated['company_id'],
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'quantity_index' => $fieldValue['quantity_index'],
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }

                                // Create StockAdjustedFieldValue (for 'unselected')
                                if ($fieldValue['value_type'] === 'unselected') {
                                    StockAdjustedFieldValue::create([
                                        'stock_adjusted_id' => $stockAdjusted->id,
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct ? $purchaseStockProduct->id : null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $branchId,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'quantity_index' => $detail['diff_stock'],
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }

                                // Create PurchaseStockProductFieldValue (for 'add' and 'unselected')
                                if ($detail['adjusted_type'] === 'add' && $fieldValue['value_type'] === 'unselected') {
                                    PurchaseStockProductFieldValue::create([
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $branchId,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct->id,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'quantity_index' => $fieldValue['quantity_index'],
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }
                            }
                        }
                    } else {
                        // Delete all existing field values for StockAdjustmentProduct if none provided
                        StockAdjustmentProductFieldValue::where('stock_adjustment_product_id', $stockAdjustmentProduct->id)->delete();
                        Log::info('No field_values for product_id: ' . $detail['product_id']);
                    }
                }


                foreach ($existingSAPs as $sapId => $existingSAP) {
                    if (!in_array($sapId, $providedSAPIds)) {

                        StockAdjustmentProductFieldValue::where('stock_adjustment_product_id', $sapId)->delete();

                        $existingSAP->delete();
                    }
                }

                return $stockAdjustment;
            });

            return response()->json($stockAdjustment->load('StockAdjustmentProduct.fieldValues'), 200);
        } catch (ModelNotFoundException $e) {
            Log::error('StockAdjustment not found: ' . $e->getMessage());
            return response()->json(['error' => 'Stock adjustment not found'], 404);
        } catch (QueryException $e) {
            Log::error('QueryException in StockAdjustmentController::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Exception in StockAdjustmentController::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_adjustments')->where('company_id', $request->company_id),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reasons' => 'nullable|string|max:255',
                'product_details' => 'required|array|min:1',
                'product_details.*.product_id' => 'required|integer|exists:products,id',
                'product_details.*.product_name' => 'required|string|max:255',
                'product_details.*.adjusted_type' => 'required|in:add,subtract',
                'product_details.*.diff_stock' => 'required|numeric',
                'product_details.*.current_stock' => 'required|numeric|min:0',
                'product_details.*.actual_stock' => 'required|numeric|min:0',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_details.*.field_values' => 'nullable|array',
                'product_details.*.field_values.*.*.product_field_id' => 'required_with:product_details.*.field_values|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'nullable|integer',
                'product_details.*.field_values.*.*.value' => 'required_with:product_details.*.field_values|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_with:product_details.*.field_values|numeric|min:0',
                'product_details.*.field_values.*.*.value_type' => 'required_with:product_details.*.field_values|in:selected,unselected',
                'company_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            $adjustment = DB::transaction(function () use ($request, $companyId, $branchId) {
                $stockAdjustment = StockAdjustment::create([
                    'reference_no' => $request->reference_no,
                    'invoice_date' => $request->invoice_date,
                    'invoice_date_bs' => $request->invoice_date_bs,
                    'document_number' => $request->document_number,
                    // 'branch_id' => $request->branch_id ?? null,
                    'remarks' => $request->remarks,
                    'reasons' => $request->reasons,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                ]);



                foreach ($request->product_details as $detail) {
                    $productId = $detail['product_id'];
                    $diffStock = $detail['diff_stock'];
                    $adjustedType = $detail['adjusted_type']; // add or subtract
                    $measureUnitId = $detail['measure_unit_id'];
                    $quantity = $diffStock;

                    $unit = MeasureUnit::find($measureUnitId);
                    $piecesPerUnit = $unit->quantity ?? 1;
                    $requiredPieces = $this->calculatePieces((string) $quantity, $piecesPerUnit);

                    // Flatten field values exactly like Sale
                    $fieldValuesFlat = $detail['field_values'] ?? [];
                    $fieldValuesFlat = is_array($fieldValuesFlat) ? $this->flattenFieldValues($fieldValuesFlat, 0) : [];

                    // Summary row (for display)
                    $stockAdjustmentProduct = StockAdjustmentProduct::create([
                        'stock_adjustment_id' => $stockAdjustment->id,
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'product_id' => $productId,
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'] ?? null,
                        'current_stock' => $detail['current_stock'],
                        'actual_stock' => $detail['actual_stock'],
                        'diff_stock' => $diffStock,
                        'quantity' => $quantity,
                        'measure_unit_id' => $measureUnitId,
                    ]);

                    if ($adjustedType === 'add') {
                        // ADD: Create real batch
                        $psp = PurchaseStockProduct::create([
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'product_id' => $productId,
                            'product_name' => $detail['product_name'],
                            'product_code' => $detail['product_code'],
                            'quantity' => $quantity,
                            'measure_unit_id' => $measureUnitId,
                            'purchase_type' => 'stock_adjustment',
                            'stock_adjustment_id' => $stockAdjustment->id,
                        ]);

                        StockAdjusted::create([
                            'stock_adjustment_id' => $stockAdjustment->id,
                            'purchase_stock_product_id' => $psp->id,
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'product_id' => $productId,
                            'product_code' => $detail['product_code'],
                            'adjusted_type' => 'add',
                            'quantity' => $quantity,
                            'diff_stock' => $diffStock,
                            'measure_unit_id' => $measureUnitId,
                        ]);

                        // Save unselected field values to new PSP
                        foreach ($fieldValuesFlat as $fv) {
                            if ($fv['value_type'] === 'unselected') {
                                PurchaseStockProductFieldValue::create([
                                    'purchase_stock_product_id' => $psp->id,
                                    'stock_adjustment_id' => $stockAdjustment->id,
                                    'company_id' => $companyId,
                                    'branch_id' => $branchId,
                                    'product_id' => $productId,
                                    'product_field_id' => $fv['product_field_id'],
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                    'value' => $fv['value'],
                                ]);
                            }
                        }
                    } else {
                        // SUBTRACT: Use real available stock (exactly like Sale)
                        $remainingPieces = $requiredPieces;
                        $usedPspIds = [];

                        if (!empty($fieldValuesFlat)) {
                            $usedPspIds = collect($fieldValuesFlat)
                                ->where('value_type', 'unselected')
                                ->pluck('purchase_stock_product_id')
                                ->filter()
                                ->unique()
                                ->values()
                                ->toArray();
                        }

                        $batches = PurchaseStockProduct::with([
                            'fieldValues',
                            'saleProducts.saleProductReturns',
                            'purchaseStockProductReturns'
                        ])
                            ->where('product_id', $productId)
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->whereNull('deleted_at');

                        if (!empty($usedPspIds)) {
                            $batches->whereIn('id', $usedPspIds);
                        } else {
                            $batches->orderBy('created_at', 'asc')->orderBy('id', 'asc');
                        }

                        $batches = $batches->get();

                        foreach ($batches as $psp) {
                            if ($remainingPieces <= 0)
                                break;

                            $availablePieces = $this->calculateAvailablePieces($psp, $companyId, $branchId, []);

                            $takePieces = min($availablePieces, $remainingPieces);
                            if ($takePieces <= 0)
                                continue;

                            $takeQty = $takePieces / $piecesPerUnit;

                            $stockAdjusted = StockAdjusted::create([
                                'stock_adjustment_id' => $stockAdjustment->id,
                                'purchase_stock_product_id' => $psp->id,
                                'company_id' => $companyId,
                                'branch_id' => $branchId,
                                'product_id' => $productId,
                                'product_name' => $psp->product_name,
                                'product_code' => $psp->product_code,
                                'adjusted_type' => 'subtract',
                                'quantity' => $takeQty,
                                'diff_stock' => -$takeQty,
                                'price' => $psp->price ?? 0,
                                'amount' => $takeQty * ($psp->price ?? 0),
                                'measure_unit_id' => $measureUnitId,
                            ]);

                            $remainingPieces -= $takePieces;
                        }

                        if ($remainingPieces > 0) {
                            throw new \Exception("Not enough available stock to subtract for product: {$detail['product_name']}");
                        }
                    }

                    // === PRESERVE YOUR ORIGINAL FIELD VALUE LOGIC (selected/unselected) ===
                    if (!empty($fieldValuesFlat)) {
                        foreach ($fieldValuesFlat as $fv) {
                            // Selected → for display in adjustment product
                            if ($fv['value_type'] === 'selected') {
                                StockAdjustmentProductFieldValue::create([
                                    'stock_adjustment_product_id' => $stockAdjustmentProduct->id,
                                    'company_id' => $companyId,
                                    'product_id' => $productId,
                                    'product_field_id' => $fv['product_field_id'],
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                    'value' => $fv['value'],
                                ]);
                            }

                            // Unselected → for tracking which batch was used
                            if ($fv['value_type'] === 'unselected') {
                                StockAdjustedFieldValue::create([
                                    'stock_adjusted_id' => $stockAdjusted->id,
                                    'stock_adjustment_id' => $stockAdjustmentProduct->id,
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? null,
                                    'company_id' => $companyId,
                                    'branch_id' => $branchId,
                                    'product_id' => $productId,
                                    'product_field_id' => $fv['product_field_id'],
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                    'value' => $fv['value'],
                                ]);
                            }
                        }
                    }
                }

                return $stockAdjustment;
            });

            return response()->json($adjustment->load('StockAdjustmentProduct.fieldValues'), 201);

        } catch (\Exception $e) {
            \Log::error('StockAdjustmentController::store Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            // Load the stock adjustment with its related products and field values
            $item = StockAdjustment::with(['StockAdjustmentProduct.fieldValues.productField'])->findOrFail($id);
            $itemArray = $item->toArray();

            foreach ($itemArray['stock_adjustment_product'] as &$stockProduct) {

                $product = Product::find($stockProduct['product_id']);
                if (!$product) {
                    continue;
                }

                $productId = $product->id;


                // Fetch measure unit IDs from both Product and ProductList
                $productMeasureUnitId = Product::where('id', $productId)->pluck('measure_unit_id')->toArray();
                $productListMeasureUnitId = ProductList::where('product_id', $productId)->pluck('measure_unit_id')->toArray();

                // Merge and filter duplicates
                $mergedMeasureUnits = collect(array_merge($productMeasureUnitId, $productListMeasureUnitId))
                    ->unique()
                    ->filter()
                    ->values();

                // Get all measure units
                $usedMeasureUnits = MeasureUnit::whereIn('id', $mergedMeasureUnits)
                    ->whereNull('deleted_at')
                    ->get(['id', 'name', 'quantity']);

                $stockProduct['measure_units'] = $usedMeasureUnits;


                // Clean and restructure field values
                foreach ($stockProduct['field_values'] as &$fieldValue) {
                    if (isset($fieldValue['product_field'])) {
                        $fieldValue['name'] = $fieldValue['product_field']['name'];
                        $fieldValue['type'] = $fieldValue['product_field']['type'];
                        $fieldValue['values'] = $fieldValue['product_field']['values'];
                        unset($fieldValue['product_field']);
                    }
                }
            }

            return response()->json($itemArray);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Stock Adjustment not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $stockAdjustment = StockAdjustment::findOrFail($id);

            $usedIn = [];

            if ($stockAdjustment->stockProductDetailsUse()->exists()) {
                $usedIn[] = 'stock product details';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Stock Adjustment cannot be deleted because it is in use by: ' . implode(', ', $usedIn) . '.',
                    'used_in' => $usedIn
                ], 400);
            }

            $stockAdjustment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock Adjustment deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'not_found',
                'message' => 'Stock Adjustment not found!'
            ], 404);
        } catch (QueryException $e) {
            \Log::error($e->getMessage());
            return response()->json([
                'error' => 'query_error',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Stock Adjustment.'
            ], 500);
        }
    }


    public function listAvailableProductsforStocks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'nullable|integer',
                'include_details' => 'nullable|boolean',

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


            \Log::info('listAvailableProducts: Processing', [
                'user_id' => auth()->id(),
                'company_id' => $companyId,
                'include_details' => $includeDetails,

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


            $products =
                $this->getAvailableProductsForSale($companyId, $branchId);


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




    public function getAvailableProductsForSale($companyId, $branchId)
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

            $purchaseProducts = PurchaseStockProduct::select('purchase_stock_products.*')   // <── essential
                ->whereIn('purchase_stock_products.product_id', $productIds)
                ->where('purchase_stock_products.company_id', $companyId)
                ->whereNull('purchase_stock_products.deleted_at')



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


}
