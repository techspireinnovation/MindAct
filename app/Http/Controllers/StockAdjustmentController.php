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
                'location_id' => 'nullable',
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
                'location_id' => 'nullable',
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
            return response()->json(['error' => 'Item not found !'], 404);
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


            $products = $includeDetails
                ? collect($this->getAvailableProductsDetails(null, null, $companyId)['data'], $branchId)
                : $this->getAvailableProductsForSale( $companyId, $branchId);


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
                        $purchasedPieces = AvailableQuantityService::calculatePieces(
                            ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                            measureUnitQuantity: isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1
                        );

                        // Calculate return pieces, capped at purchased pieces
                        $returnPieces = $pp->purchaseStockProductReturns->reduce(
                            fn($carry, $return) => $carry + AvailableQuantityService::calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $returnPieces = min($returnPieces, $purchasedPieces);

                        // Calculate sale and sales return pieces
                        $salePieces = $pp->saleProducts->reduce(
                            fn($carry, $sale) => $carry + AvailableQuantityService::calculatePieces(
                                ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                                isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $salesReturnPieces = $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                            fn($carry, $return) => $carry + AvailableQuantityService::calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );

                        // Calculate available pieces
                        $availablePieces = AvailableQuantityService::calculateAvailablePieces($pp, $companyId, $branchId, $measureUnitsCalc);

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
                    fn($pp) => AvailableQuantityService::calculatePieces(
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
