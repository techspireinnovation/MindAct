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
            \Log::info('Request product_details:', $request->product_details);

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
                'product_details' => 'required|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.adjusted_type' => 'required_with:product_details|in:add,subtract',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.measure_unit_id' => 'required_with:product_details|integer|exists:measure_units,id',
                'product_details.*.branch_id' => 'nullable|integer|exists:branches,id',
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
                'product_details.*.field_values.*.*.stock_adjustemnt_id' => 'nullable|numeric',
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

            $item = DB::transaction(function () use ($validated, $request) {
                $stockAdjustment = StockAdjustment::create([
                    'reference_no' => $validated['reference_no'],
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'document_number' => $validated['document_number'] ?? null,
                    'location_id' => $validated['location_id'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'reasons' => $validated['reasons'] ?? null,
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
                ]);

                foreach ($validated['product_details'] as $detail) {
                    $detail['field_values'] = $detail['field_values'] ?? [];

                    // Summary row (for display)
                    $stockAdjustmentProduct = StockAdjustmentProduct::create([
                        'stock_adjustment_id' => $stockAdjustment->id,
                        'purchase_stock_product_id' => null,
                        'customer_id' => null,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $stockAdjustment->branch_id,
                        'purchase_product_id' => null,
                        'stock_product_id' => null,
                        'purchase_id' => null,
                        'product_id' => $detail['product_id'],
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'] ?? null,
                        'hs_code' => $detail['hs_code'] ?? null,
                        'mfd' => $detail['mfd'] ?? null,
                        'expiry_date' => $detail['expiry_date'] ?? null,
                        'purchase_type' => $detail['purchase_type'] ?? null,
                        'current_stock' => $detail['current_stock'],
                        'actual_stock' => $detail['actual_stock'],
                        'diff_stock' => $detail['diff_stock'],
                        'quantity' => abs($detail['diff_stock']),
                        'free_quantity' => $detail['free_quantity'] ?? 0,
                        'price' => $detail['price'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'amount' => $detail['amount'] ?? 0,
                        'is_vatable' => $detail['is_vatable'] ?? null,
                        'measure_unit_id' => $detail['measure_unit_id'],
                    ]);

                    $purchaseStockProduct = null;
                    $usedPspIds = []; // For field_values case

                    if ($detail['adjusted_type'] === 'add') {
                        // ADD: Create new batch
                        $purchaseStockProduct = PurchaseStockProduct::create([
                            'customer_id' => null,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $stockAdjustment->branch_id,
                            'stock_adjustment_id' => $stockAdjustment->id,
                            'purchase_type' => $detail['purchase_type'] ?? 'stock_adjustment',
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'product_id' => $detail['product_id'],
                            'product_name' => $detail['product_name'],
                            'product_code' => $detail['product_code'] ?? null,
                            'hs_code' => $detail['hs_code'] ?? null,
                            'mfd' => $detail['mfd'] ?? null,
                            'expiry_date' => $detail['expiry_date'] ?? null,
                            'quantity' => abs($detail['diff_stock']),
                            'free_quantity' => $detail['free_quantity'] ?? 0,
                            'price' => $detail['price'] ?? 0,
                            'discount_percent' => $detail['discount_percent'] ?? 0,
                            'discount_amount' => $detail['discount_amount'] ?? 0,
                            'amount' => $detail['amount'] ?? 0,
                            'is_vatable' => $detail['is_vatable'] ?? null,
                            'measure_unit_id' => $detail['measure_unit_id'],
                        ]);

                        // Link this new PSP in StockAdjusted
                        StockAdjusted::create([
                            'stock_adjustment_id' => $stockAdjustment->id,
                            'purchase_stock_product_id' => $purchaseStockProduct->id,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $stockAdjustment->branch_id,
                            'product_id' => $detail['product_id'],
                            'product_code' => $detail['product_code'], 
                            'adjusted_type' => 'add',
                            'product_name' => $detail['product_name'],
                            'quantity' => abs($detail['diff_stock']),
                            'diff_stock' => $detail['diff_stock'],
                            'price' => $detail['price'] ?? 0,
                            'amount' => $detail['amount'] ?? 0,
                            'measure_unit_id' => $detail['measure_unit_id'],
                        ]);

                    } else {
                        // SUBTRACT: Determine which PSP(s) to link
                        if (!empty($detail['field_values'])) {
                            // Case 1: Batch/Serial → Use PSP IDs from field_values (unselected)
                            foreach ($detail['field_values'] as $group) {
                                foreach ($group as $fv) {
                                    if ($fv['value_type'] === 'unselected' && !empty($fv['purchase_stock_product_id'])) {
                                        $usedPspIds[] = $fv['purchase_stock_product_id'];
                                    }
                                }
                            }
                        }

                        if (empty($usedPspIds)) {
                            // Case 2: No field_values → Use FIFO (oldest first)
                            $qtyNeeded = abs($detail['diff_stock']);
                            $remaining = $qtyNeeded;

                            $fifoBatches = PurchaseStockProduct::where('product_id', $detail['product_id'])
                                ->where('company_id', $validated['company_id'])
                                ->where('branch_id', $stockAdjustment->branch_id)
                                ->where('quantity', '>', 0)
                                ->orderBy('created_at', 'asc')
                                ->orderBy('id', 'asc')
                                ->get();

                            foreach ($fifoBatches as $psp) {
                                if ($remaining <= 0)
                                    break;
                                $take = min($psp->quantity, $remaining);

                                StockAdjusted::create([
                                    'stock_adjustment_id' => $stockAdjustment->id,
                                    'purchase_stock_product_id' => $psp->id,
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $stockAdjustment->branch_id,
                                    'product_id' => $detail['product_id'],
                                    'product_code' => $detail['product_code'], 
                                    'adjusted_type' => 'subtract',
                                    'product_name' => $psp->product_name,
                                    'quantity' => $take,
                                    'diff_stock' => -$take,
                                    'price' => $psp->price,
                                    'amount' => $take * $psp->price,
                                    'measure_unit_id' => $psp->measure_unit_id,
                                ]);

                                $remaining -= $take;
                            }
                        } else {
                            // Case 3: Use specific PSPs from field_values
                            foreach ($usedPspIds as $pspId) {
                                $psp = PurchaseStockProduct::find($pspId);
                                if ($psp) {
                                    StockAdjusted::create([
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $psp->id,
                                        'product_code' =>$detail['product_code'],
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $stockAdjustment->branch_id,
                                        'product_id' => $detail['product_id'],
                                        'adjusted_type' => 'subtract',
                                        'product_name' => $psp->product_name,
                                        'quantity' => abs($detail['diff_stock']), // or split per batch if needed
                                        'diff_stock' => $detail['diff_stock'],
                                        'price' => $psp->price,
                                        'amount' => abs($detail['diff_stock']) * $psp->price,
                                        'measure_unit_id' => $psp->measure_unit_id,
                                    ]);
                                }
                            }
                        }
                    }

                    // === FIELD VALUES LOGIC (UNCHANGED & FULLY PRESERVED) ===
                    if (!empty($detail['field_values'])) {
                        foreach ($detail['field_values'] as $fieldValueGroup) {
                            foreach ($fieldValueGroup as $fieldValue) {
                                if ($fieldValue['value_type'] == 'selected') {
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

                                if ($fieldValue['value_type'] == 'unselected') {
                                    StockAdjustedFieldValue::create([
                                        'stock_adjusted_id' => StockAdjusted::latest('id')->first()->id ?? null,
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct->id ?? null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $stockAdjustment->branch_id,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'quantity_index' => $detail['diff_stock'],
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }

                                if ($detail['adjusted_type'] === 'add' && $fieldValue['value_type'] == 'unselected') {
                                    PurchaseStockProductFieldValue::create([
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $stockAdjustment->branch_id,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct->id ?? null,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'quantity_index' => $fieldValue['quantity_index'],
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }
                            }
                        }
                    }
                }

                return $stockAdjustment;
            });

            return response()->json($item->load('StockAdjustmentProduct.fieldValues'), 201);

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

                    return AvailableQuantityService::calculatePieces(
                        ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                        $measureUnitsCalc[$pp->measure_unit_id]?->quantity ?? 1
                    );

                });

                $returnPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->purchaseStockProductReturns->reduce(
                        fn($carry, $return) => $carry + AvailableQuantityService::calculatePieces(
                            ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                            $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });
                $returnPieces = min($returnPieces, $purchasedPieces);

                $salePieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->saleProducts->reduce(
                        fn($carry, $sale) => $carry + AvailableQuantityService::calculatePieces(
                            ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                            $measureUnitsCalc[$sale->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });

                $salesReturnPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                        fn($carry, $return) => $carry + AvailableQuantityService::calculatePieces(
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
