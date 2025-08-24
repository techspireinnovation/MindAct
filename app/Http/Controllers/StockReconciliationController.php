<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseStockProductFieldValue;
use App\Models\StockEntry;
use App\Models\StockReconciliated;
use App\Models\StockReconciliatedFieldValue;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationDetail;
use App\Models\StockReconciliationFieldValue;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


class StockReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockReconciliation::query();


        return response()->json($query->paginate(50));
    }



    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'date_bs' => 'nullable|string|max:255',
                'reconciliation_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('stock_reconciliations')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })
                ],
                'document_no' => 'nullable|string|max:255',
                'branch_id' => 'nullable|exists:branches,id',
                'date_ad' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'product_details' => 'required|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.reconciliated_type' => 'required_with:product_details|in:add,subtract',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric',
                'product_details.*.current_stock' => 'required_with:product_details|numeric',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.measure_unit_id' => 'required_with:product_details|integer|exists:measure_units,id',
                'product_details.*.product_code' => 'nullable|string|max:255',
                'product_details.*.hs_code' => 'nullable|string|max:255',
                'product_details.*.mfd' => 'nullable|string|max:255',
                'product_details.*.expiry_date' => 'nullable|string|max:255',
                'product_details.*.purchase_type' => 'nullable|string|max:255',
                'product_details.*.quantity' => 'nullable|numeric',
                'product_details.*.free_quantity' => 'nullable|numeric',
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
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            $stockReconciliation = DB::transaction(function () use ($validated, $request) {
                $stockReconciliation = StockReconciliation::create([
                    'company_id' => $validated['company_id'],
                    'date_bs' => $validated['date_bs'] ?? null,
                    'reconciliation_no' => $validated['reconciliation_no'] ?? null,
                    'document_no' => $validated['document_no'] ?? null,
                    'branch_id' => $validated['branch_id'] ?? null,
                    'date_ad' => $validated['date_ad'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                ]);

                if (isset($validated['product_details'])) {
                    foreach ($validated['product_details'] as $detail) {
                        // Ensure field_values is empty if not provided
                        if (!isset($detail['field_values'])) {
                            $detail['field_values'] = [];
                        }

                        $commonData = [
                            'company_id' => $request->company_id,
                            'branch_id' => $request->branch_id ?? null,
                            'product_id' => $detail['product_id'],
                            'product_name' => $detail['product_name'],
                            'product_code' => $detail['product_code'] ?? null,
                            'hs_code' => $detail['hs_code'] ?? null,
                            'mfd' => $detail['mfd'] ?? null,
                            'expiry_date' => $detail['expiry_date'] ?? null,
                            'quantity' => $detail['quantity'] ?? null,
                            'free_quantity' => $detail['free_quantity'] ?? 0,
                            'price' => $detail['price'] ?? 0,
                            'discount_percent' => $detail['discount_percent'] ?? 0,
                            'discount_amount' => $detail['discount_amount'] ?? 0,
                            'amount' => $detail['amount'] ?? 0,
                            'is_vatable' => $detail['is_vatable'] ?? null,
                            'measure_unit_id' => $detail['measure_unit_id'],
                        ];

                        // Create StockReconciliationDetail
                        $stockReconciliationDetail = StockReconciliationDetail::create(array_merge($commonData, [
                            'stock_reconciliation_id' => $stockReconciliation->id,
                            'purchase_stock_product_id' => null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'current_stock' => $detail['current_stock'],
                            'actual_stock' => $detail['actual_stock'],
                            'diff_stock' => $detail['diff_stock'] ?? null,
                        ]));


                        $stockReconciliated = StockReconciliated::create(array_merge($commonData, [
                            'stock_reconciliation_id' => $stockReconciliation->id,
                            'purchase_stock_product_id' => null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'reconciliated_type' => $detail['reconciliated_type'],
                            'quantity' => $detail['diff_stock'] ?? null,
                            'diff_stock' => $detail['diff_stock'] ?? null,
                        ]));

                        // Create PurchaseStockProduct for 'add' reconciliations
                        $purchaseStockProduct = null;
                        if ($detail['reconciliated_type'] == 'add') {
                            $purchaseStockProduct = PurchaseStockProduct::create(array_merge($commonData, [
                                'stock_reconciliation_id' => $stockReconciliation->id,
                                'customer_id' => null,
                                'purchase_product_id' => null,
                                'stock_product_id' => null,
                                'purchase_id' => null,
                                'purchase_type' => $detail['purchase_type'] ?? null,
                                'quantity' => $detail['diff_stock'] ?? null,
                            ]));
                        }

                        // Process field values
                        if (!empty($detail['field_values'])) {
                            Log::info('Processing field_values for product_id: ' . $detail['product_id'], $detail['field_values']);
                            foreach ($detail['field_values'] as $fieldValueGroup) {
                                foreach ($fieldValueGroup as $fieldValue) {

                                    if ($fieldValue['value_type'] == 'selected') {
                                        StockReconciliationFieldValue::create([
                                            'stock_reconciliation_detail_id' => $stockReconciliationDetail->id,
                                            'stock_reconciliation_id' => $stockReconciliation->id,
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

                                    // Create StockReconciliatedFieldValue (for 'unselected')
                                    if ($fieldValue['value_type'] == 'unselected') {
                                        StockReconciliatedFieldValue::create([
                                            'stock_reconciliated_id' => $stockReconciliated->id,
                                            'stock_reconciliation_id' => $stockReconciliation->id,
                                            'purchase_stock_product_id' => $purchaseStockProduct ? $purchaseStockProduct->id : null,
                                            'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                            'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                            'company_id' => $validated['company_id'],
                                            'branch_id' => $detail['branch_id'] ?? $request->branch_id ?? null,
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'product_id' => $detail['product_id'],
                                            'quantity_index' => $detail['diff_stock'] ?? ($detail['physical_stock'] - $detail['available_stock']),
                                            'quantity_type' => $fieldValue['quantity_type'],
                                            'value' => $fieldValue['value'],
                                        ]);
                                    }

                                    // Create PurchaseStockProductFieldValue (for 'add' and 'unselected')
                                    if ($detail['reconciliated_type'] == 'add' && $fieldValue['value_type'] == 'unselected') {
                                        PurchaseStockProductFieldValue::create([
                                            'company_id' => $validated['company_id'],
                                            'branch_id' => $detail['branch_id'] ?? $request->branch_id ?? null,
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'product_id' => $detail['product_id'],
                                            'stock_reconciliation_id' => $stockReconciliation->id,
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
                            Log::info('No field_values for product_id: ' . $detail['product_id']);
                        }
                    }
                }

                return $stockReconciliation;
            });

            return response()->json([
                'message' => 'Stock Reconciliation created successfully',
                'data' => $stockReconciliation->load('stockReconciliationDetails'),
            ], 201);
        } catch (QueryException $e) {
            dd($e->getMessage());
            Log::error('Database error in StockReconciliationController::store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {

            Log::error('Unexpected error in StockReconciliationController::store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }





    public function show($id): JsonResponse
    {
        try {
            $item = StockReconciliation::with('stockReconciliationDetails.fieldValues')->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Log the input request for debugging
            Log::info('Update request product_details:', $request->product_details);

            // Validation rules
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'date_bs' => 'nullable|string|max:255',
                'reconciliation_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_reconciliations')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');
                        }),
                ],
                'document_no' => 'nullable|string|max:255',
                'branch_id' => 'nullable|integer|exists:branches,id',
                'date_ad' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'product_details' => 'required|array',
                'product_details.*.id' => 'nullable|integer|exists:stock_reconciliation_details,id',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.reconciliated_type' => 'required_with:product_details|in:add,subtract',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.measure_unit_id' => 'required_with:product_details|integer|exists:measure_units,id',
                'product_details.*.product_code' => 'nullable|string|max:255',
                'product_details.*.hs_code' => 'nullable|string|max:255',
                'product_details.*.mfd' => 'nullable|string|max:255',
                'product_details.*.expiry_date' => 'nullable|string|max:255',
                'product_details.*.purchase_type' => 'nullable|string|max:255',
                'product_details.*.quantity' => 'nullable|numeric',
                'product_details.*.free_quantity' => 'nullable|numeric',
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
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            $stockReconciliation = DB::transaction(function () use ($validated, $id, $request) {
                $stockReconciliation = StockReconciliation::findOrFail($id);

                // Update main StockReconciliation record
                $stockReconciliation->update([
                    'company_id' => $validated['company_id'],
                    'date_bs' => $validated['date_bs'] ?? null,
                    'reconciliation_no' => $validated['reconciliation_no'],
                    'document_no' => $validated['document_no'] ?? null,
                    'branch_id' => $validated['branch_id'] ?? null,
                    'date_ad' => $validated['date_ad'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                ]);

                // Get existing StockReconciliationDetail records
                $existingDetails = StockReconciliationDetail::where('stock_reconciliation_id', $stockReconciliation->id)
                    ->get()
                    ->keyBy('id');

                // Delete all existing related records (and their field values)
                StockReconciliationFieldValue::where('stock_reconciliation_id', $stockReconciliation->id)->delete();
                StockReconciliatedFieldValue::where('stock_reconciliation_id', $stockReconciliation->id)->delete();
                StockReconciliated::where('stock_reconciliation_id', $stockReconciliation->id)->delete();
                PurchaseStockProductFieldValue::where('stock_reconciliation_id', $stockReconciliation->id)->delete();
                PurchaseStockProduct::where('stock_reconciliation_id', $stockReconciliation->id)
                    ->whereNull('purchase_id')
                    ->delete();

                $providedDetailIds = [];

                $productDetails = $validated['product_details'];

                foreach ($productDetails as $detail) {
                    // Ensure field_values is empty if not provided
                    if (!isset($detail['field_values'])) {
                        $detail['field_values'] = [];
                    }

                    $detailId = $detail['id'] ?? null;
                    $productId = $detail['product_id'];
                    $branchId = $detail['branch_id'] ?? $request->branch_id ?? null;

                    $commonData = [
                        'company_id' => $validated['company_id'],
                        'branch_id' => $branchId,
                        'product_id' => $productId,
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'] ?? null,
                        'hs_code' => $detail['hs_code'] ?? null,
                        'mfd' => $detail['mfd'] ?? null,
                        'expiry_date' => $detail['expiry_date'] ?? null,
                        'quantity' => $detail['quantity'] ?? null,
                        'free_quantity' => $detail['free_quantity'] ?? 0,
                        'price' => $detail['price'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'amount' => $detail['amount'] ?? 0,
                        'is_vatable' => $detail['is_vatable'] ?? null,
                        'measure_unit_id' => $detail['measure_unit_id'],
                    ];

                    // Handle StockReconciliationDetail
                    if ($detailId && ($existingDetail = $existingDetails->get($detailId))) {
                        $providedDetailIds[] = $detailId;
                        $existingDetail->update(array_merge($commonData, [
                            'stock_reconciliation_id' => $stockReconciliation->id,
                            'purchase_stock_product_id' => null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'current_stock' => $detail['current_stock'],
                            'actual_stock' => $detail['actual_stock'],
                            'diff_stock' => $detail['diff_stock'],
                        ]));
                        $stockReconciliationDetail = $existingDetail;
                    } else {
                        // Create new if ID not provided or not found
                        $stockReconciliationDetail = StockReconciliationDetail::create(array_merge($commonData, [
                            'stock_reconciliation_id' => $stockReconciliation->id,
                            'purchase_stock_product_id' => null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'current_stock' => $detail['current_stock'],
                            'actual_stock' => $detail['actual_stock'],
                            'diff_stock' => $detail['diff_stock'],
                        ]));
                        if ($detailId) {
                            Log::warning('StockReconciliationDetail ID provided but not found, created new', ['detail_id' => $detailId]);
                            $providedDetailIds[] = $stockReconciliationDetail->id;
                        }
                    }

                    // Create new StockReconciliated record
                    $stockReconciliated = StockReconciliated::create(array_merge($commonData, [
                        'stock_reconciliation_id' => $stockReconciliation->id,
                        'purchase_stock_product_id' => null,
                        'purchase_product_id' => null,
                        'stock_product_id' => null,
                        'purchase_id' => null,
                        'purchase_type' => $detail['purchase_type'] ?? null,
                        'reconciliated_type' => $detail['reconciliated_type'],
                        'quantity' => $detail['diff_stock'],
                        'diff_stock' => $detail['diff_stock'],
                    ]));

                    // Create new PurchaseStockProduct for 'add' reconciliations
                    $purchaseStockProduct = null;
                    if ($detail['reconciliated_type'] === 'add') {
                        $purchaseStockProduct = PurchaseStockProduct::create(array_merge($commonData, [
                            'stock_reconciliation_id' => $stockReconciliation->id,
                            'customer_id' => null,
                            'purchase_product_id' => null,
                            'stock_product_id' => null,
                            'purchase_id' => null,
                            'purchase_type' => $detail['purchase_type'] ?? null,
                            'quantity' => $detail['diff_stock'],
                        ]));
                    }

                    // Process field values
                    if (!empty($detail['field_values'])) {
                        Log::info('Processing field_values for product_id: ' . $detail['product_id'], $detail['field_values']);

                        // Delete existing field values for StockReconciliationDetail
                        StockReconciliationFieldValue::where('stock_reconciliation_detail_id', $stockReconciliationDetail->id)->delete();

                        foreach ($detail['field_values'] as $fieldValueGroup) {
                            foreach ($fieldValueGroup as $fieldValue) {
                                // Create StockReconciliationFieldValue (for 'selected')
                                if ($fieldValue['value_type'] === 'selected') {
                                    StockReconciliationFieldValue::create([
                                        'stock_reconciliation_detail_id' => $stockReconciliationDetail->id,
                                        'stock_reconciliation_id' => $stockReconciliation->id,
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

                                // Create StockReconciliatedFieldValue (for 'unselected')
                                if ($fieldValue['value_type'] === 'unselected') {
                                    StockReconciliatedFieldValue::create([
                                        'stock_reconciliated_id' => $stockReconciliated->id,
                                        'stock_reconciliation_id' => $stockReconciliation->id,
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
                                if ($detail['reconciliated_type'] === 'add' && $fieldValue['value_type'] === 'unselected') {
                                    PurchaseStockProductFieldValue::create([
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $branchId,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $detail['product_id'],
                                        'stock_reconciliation_id' => $stockReconciliation->id,
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
                        // Delete all existing field values for StockReconciliationDetail if none provided
                        StockReconciliationFieldValue::where('stock_reconciliation_detail_id', $stockReconciliationDetail->id)->delete();
                        Log::info('No field_values for product_id: ' . $detail['product_id']);
                    }
                }

                // Delete StockReconciliationDetail records not in provided IDs
                foreach ($existingDetails as $detailId => $existingDetail) {
                    if (!in_array($detailId, $providedDetailIds)) {
                        StockReconciliationFieldValue::where('stock_reconciliation_detail_id', $detailId)->delete();
                        $existingDetail->delete();
                    }
                }

                return $stockReconciliation;
            });

            return response()->json([
                'message' => 'Stock Reconciliation updated successfully',
                'data' => $stockReconciliation->load('stockReconciliationDetails.fieldValues'),
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::error('StockReconciliation not found: ' . $e->getMessage());
            return response()->json(['error' => 'Stock reconciliation not found'], 404);
        } catch (QueryException $e) {
            Log::error('QueryException in StockReconciliationController::update: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Exception in StockReconciliationController::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $item = StockReconciliation::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Reconciliation deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Reconciliation not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
