<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\StockAdjustmentProduct;
use App\Models\StockAdjustmentProductFieldValue;
use App\Models\PurchaseStockProduct;
use App\Models\StockAdjustedFieldValue;
use App\Models\StockAdjusted;
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
                'location_id' => 'required|exists:locations,id',
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
                'product_details.*.field_values.*.*.value' => 'required_with:product_details.*.field_values|string|max:255',
                'product_details.*.field_values.*.*.quantity_type' => 'nullable|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_with:product_details.*.field_values|numeric|min:0',
                'product_details.*.field_values.*.*.value_type' => 'required_with:product_details.*.field_values|string|in:selected,unselected',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

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
            // Log the input request for debugging
            \Log::info('Request product_details:', $request->product_details);

            // Validation rules
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_adjustments')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id);
                    }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                'location_id' => 'required|exists:locations,id',
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
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $item = DB::transaction(function () use ($validated, $request) {

                $stockAdjustment = StockAdjustment::create([
                    'reference_no' => $validated['reference_no'],
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'document_number' => $validated['document_number'] ?? null,
                    'location_id' => $validated['location_id'],
                    'remarks' => $validated['remarks'] ?? null,
                    'reasons' => $validated['reasons'] ?? null,
                    'company_id' => $validated['company_id'],
                ]);

                // Process product details
                $productDetails = $validated['product_details'];
                foreach ($productDetails as $detail) {
                    // Ensure field_values is empty if not provided
                    if (!isset($detail['field_values'])) {
                        $detail['field_values'] = [];
                    }

                    $stockAdjustmentProduct = StockAdjustmentProduct::create([
                        'stock_adjustment_id' => $stockAdjustment->id,
                        'purchase_stock_product_id' => null,
                        'customer_id' => null,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $detail['branch_id'] ?? $request->branch_id ?? null,
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
                        'quantity' => $detail['current_stock'],
                        'free_quantity' => $detail['free_quantity'] ?? 0,
                        'price' => $detail['price'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'amount' => $detail['amount'] ?? 0,
                        'is_vatable' => $detail['is_vatable'] ?? null,
                        'measure_unit_id' => $detail['measure_unit_id'],
                    ]);

                    // Create StockAdjusted record
                    $stockAdjusted = StockAdjusted::create([
                        'stock_adjustment_id' => $stockAdjustment->id,
                        'purchase_stock_product_id' => null,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $detail['branch_id'] ?? $request->branch_id ?? null,
                        'product_id' => $detail['product_id'],
                        'adjusted_type' => $detail['adjusted_type'],
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'] ?? null,
                        'hs_code' => $detail['hs_code'] ?? null,
                        'mfd' => $detail['mfd'] ?? null,
                        'expiry_date' => $detail['expiry_date'] ?? null,
                        'purchase_type' => $detail['purchase_type'] ?? null,
                        'quantity' => $detail['diff_stock'],
                        'diff_stock' => $detail['diff_stock'],
                        'free_quantity' => $detail['free_quantity'] ?? 0,
                        'price' => $detail['price'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'amount' => $detail['amount'] ?? 0,
                        'is_vatable' => $detail['is_vatable'] ?? null,
                        'measure_unit_id' => $detail['measure_unit_id'],
                    ]);

                    // Create PurchaseStockProduct for 'add' adjustments
                    $purchaseStockProduct = null;
                    if ($detail['adjusted_type'] === 'add') {
                        $purchaseStockProduct = PurchaseStockProduct::create([
                            'customer_id' => null,
                            'company_id' => $validated['company_id'],
                            'stock_adjustment_id' => $stockAdjustment->id,
                            'branch_id' => $detail['branch_id'] ?? $request->branch_id ?? null,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'product_id' => $detail['product_id'],
                            'product_name' => $detail['product_name'],
                            'product_code' => $detail['product_code'] ?? null,
                            'hs_code' => $detail['hs_code'] ?? null,
                            'mfd' => $detail['mfd'] ?? null,
                            'expiry_date' => $detail['expiry_date'] ?? null,

                            'quantity' => $detail['diff_stock'],
                            'free_quantity' => $detail['free_quantity'] ?? 0,
                            'price' => $detail['price'] ?? 0,
                            'discount_percent' => $detail['discount_percent'] ?? 0,
                            'discount_amount' => $detail['discount_amount'] ?? 0,
                            'amount' => $detail['amount'] ?? 0,
                            'is_vatable' => $detail['is_vatable'] ?? null,
                            'measure_unit_id' => $detail['measure_unit_id'],
                        ]);
                    }

                    // Process field values
                    if (!empty($detail['field_values'])) {
                        \Log::info('Processing field_values for product_id: ' . $detail['product_id'], $detail['field_values']);
                        foreach ($detail['field_values'] as $fieldValueGroup) {
                            foreach ($fieldValueGroup as $fieldValue) {
                                // Create StockAdjustmentProductFieldValue record (for 'selected')
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

                                // Create StockAdjustedFieldValue record (for 'unselected')
                                if ($fieldValue['value_type'] == 'unselected') {
                                    StockAdjustedFieldValue::create([
                                        'stock_adjusted_id' => $stockAdjusted->id,
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct->id ?? null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $request->branch_id ?? null,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'quantity_index' => $detail['diff_stock'], // Match diff_stock
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }

                                // Create PurchaseStockProductFieldValue for 'add' adjustments (for 'unselected')
                                if ($detail['adjusted_type'] === 'add' && $fieldValue['value_type'] == 'unselected') {
                                    PurchaseStockProductFieldValue::create([
                                        'company_id' => $request->company_id ?? null,
                                        'branch_id' => $request->branch_id ?? null,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'stock_adjustment_id' => $stockAdjustment->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct->id ?? null,
                                        'purchase_product_id' => $detail['purchase_product_id'] ?? null,
                                        'stock_product_id' => $detail['stock_product_id'] ?? null,
                                        'quantity_index' => $fieldValue['quantity_index'],
                                        'quantity_type' => $fieldValue['quantity_type'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }
                            }
                        }
                    } else {
                        \Log::info('No field_values for product_id: ' . $detail['product_id']);
                    }
                }

                return $stockAdjustment;
            });

            return response()->json($item->load('StockAdjustmentProduct.fieldValues'), 201);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in StockAdjustmentController::store: ' . $e->getMessage());
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in StockAdjustmentController::store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in StockAdjustmentController::store: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = StockAdjustment::with('StockAdjustmentProduct.fieldValues')->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Adjustment not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = StockAdjustment::with('stockProductDetails')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Adjustment deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Adjustment not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}
