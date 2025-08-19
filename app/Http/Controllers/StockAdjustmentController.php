<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\StockAdjustmentProduct;
use App\Models\StockAdjustmentProductFieldValue;
use App\Models\PurchaseStockProductFieldValue;
use App\Models\ProductField;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
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
                'product_details' => 'nullable|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.unit_id' => 'required_with:product_details|numeric|max:50',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $stockAdjustment = DB::transaction(function () use ($validated, $id) {
                $stockAdjustment = StockAdjustment::findOrFail($id);

                // Convert product_details to JSON string if present, or set to null
                if (isset($validated['product_details'])) {
                    $validated['product_details'] = json_encode($validated['product_details']);
                } else {
                    $validated['product_details'] = null;
                }

                // Update main record
                $stockAdjustment->update($validated);

                // Handle product details
                // Delete all existing details to replace with new ones
                $stockAdjustment->stockProductDetails()->delete();

                if (isset($validated['product_details'])) {
                    $productDetails = json_decode($validated['product_details'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('Invalid product details format: ' . json_last_error_msg());
                    }

                    $details = [];
                    foreach ($productDetails as $detail) {
                        $detail['stock_adjustment_id'] = $stockAdjustment->id;
                        $detail['company_id'] = $validated['company_id'];
                        $details[] = $detail;
                    }
                    $stockAdjustment->stockProductDetails()->createMany($details);
                }

                return $stockAdjustment;
            });

            return response()->json($stockAdjustment->load('stockProductDetails'), 200);
        } catch (ModelNotFoundException $e) {
            \Log::error('StockAdjustment not found: ' . $e->getMessage());
            return response()->json(['error' => 'Stock adjustment not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in StockAdjustmentController::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in StockAdjustmentController::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {
        try {
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
                'product_details.*.measure_unit_id' => 'required_with:product_details|integer|exists:measure_units,id',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
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
                'product_details.*.is_vatable' => 'required|boolean',
                'product_details.*.field_values' => 'nullable|array',
                'product_details.*.field_values.*.product_field_id' => 'required_with:product_details.*.field_values|integer|exists:product_fields,id',
                'product_details.*.field_values.*.value' => 'required_with:product_details.*.field_values|string|max:255',
                'product_details.*.field_values.*.quantity_type' => 'required_with:product_details.*.field_values|string|max:255',
                'product_details.*.field_values.*.quantity_index' => 'required_with:product_details.*.field_values|numeric|min:0',
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
                    // Validate available stock for subtraction
                    if ($detail['adjusted_type'] === 'subtract') {
                        $availableProducts = $this->getAvailableProductsForSale($detail['purchase_type'] ?? null, $validated['company_id']);
                        $availableProduct = $availableProducts->firstWhere('id', $detail['product_id']);
                        if (!$availableProduct || $availableProduct->available_quantity < $detail['diff_stock']) {
                            throw new \Exception("Insufficient stock for product ID {$detail['product_id']} to subtract {$detail['diff_stock']} units.");
                        }
                    }

                    // Create StockAdjustmentProduct record
                    $stockAdjustmentProduct = StockAdjustmentProduct::create([
                        'stock_adjustment_id' => $stockAdjustment->id,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $detail['branch_id'] ?? null,
                        'product_id' => $detail['product_id'],
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'] ?? null,
                        'hs_code' => $detail['hs_code'] ?? null,
                        'mfd' => $detail['mfd'] ?? null,
                        'expiry_date' => $detail['expiry_date'] ?? null,
                        'quantity' => $detail['diff_stock'], // Store diff_stock as quantity
                        'free_quantity' => $detail['free_quantity'] ?? 0,
                        'price' => $detail['price'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'amount' => $detail['amount'] ?? 0,
                        'is_vatable' => $detail['is_vatable'],
                        'measure_unit_id' => $detail['measure_unit_id'],
                    ]);

                    // Process field values for granular adjustments
                    if (!empty($detail['field_values'])) {
                        foreach ($detail['field_values'] as $fieldValue) {
                            // Create StockAdjustmentProductFieldValue record
                            StockAdjustmentProductFieldValue::create([
                                'stock_adjustment_product_id' => $stockAdjustmentProduct->id,
                                'company_id' => $validated['company_id'],
                                'product_field_id' => $fieldValue['product_field_id'],
                                'product_id' => $detail['product_id'],
                                'quantity_index' => $fieldValue['quantity_index'],
                                'quantity_type' => $fieldValue['quantity_type'],
                                'value' => $fieldValue['value'],
                            ]);

                          
                            $purchaseFieldValue = PurchaseStockProductFieldValue::where('product_id', $detail['product_id'])
                                ->where('company_id', $validated['company_id'])
                                ->where('product_field_id', $fieldValue['product_field_id'])
                                ->where('value', $fieldValue['value'])
                                ->whereNull('deleted_at')
                                ->first();

                            if ($purchaseFieldValue) {
                                $adjustedQuantity = $detail['adjusted_type'] === 'add'
                                    ? ($purchaseFieldValue->quantity_index + $fieldValue['quantity_index'])
                                    : ($purchaseFieldValue->quantity_index - $fieldValue['quantity_index']);

                                if ($adjustedQuantity < 0) {
                                    throw new \Exception("Cannot subtract {$fieldValue['quantity_index']} from product ID {$detail['product_id']} (field ID {$fieldValue['product_field_id']}) as it would result in negative stock.");
                                }

                                $purchaseFieldValue->quantity_index = $adjustedQuantity;
                                $purchaseFieldValue->save();
                            } else {
                                // Optionally create a new PurchaseStockProductFieldValue if none exists
                                PurchaseStockProductFieldValue::create([
                                    'company_id' => $validated['company_id'],
                                    'product_id' => $detail['product_id'],
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'quantity_index' => $detail['adjusted_type'] === 'add' ? $fieldValue['quantity_index'] : 0,
                                    'quantity_type' => $fieldValue['quantity_type'],
                                    'value' => $fieldValue['value'],
                                ]);
                            }
                        }
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
            $item = StockAdjustment::with('stockProductDetails')->findOrFail($id);
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
