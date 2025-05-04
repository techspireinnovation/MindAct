<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Models\ProductList;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductFieldValue;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query();
    
        if ($request->has('keywords')) {
            $query->where('ref_bill_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Purchase::findOrFail($id);

            $validated = $request->validate([
                'ref_bill_number' => 'required|string|max:255',
                'customer_id' => 'required|exists:customers,id',
                'purchase_bill_number' => [
                    'string',
                    'max:255',
                    Rule::unique('purchases')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id));
                        }),
                ],
                'remarks' => 'string|max:255',
                'invoice_date' => 'string|max:255',
                'expiry_date' => 'string|max:255',
                'batch_no' => [
                    'string',
                    'max:255',
                    Rule::unique('purchases')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id));
                        }),
                ],
                'discount_percent' => 'numeric',
                'freight_amount' => 'numeric',
                'health_insurance' => 'numeric',
                'balance' => 'numeric',
                'excise_duty' => 'numeric',
                'discount_amount' => 'numeric',
                'discount_after_vat' => 'numeric',
                'roundoff_amount' => 'numeric',
                'payment_type' => 'string|in:cash,bank,credit',
                'discount_amount_vat' => 'numeric',
                'store_id' => 'integer|exists:stores,id',
                'location_id' => 'integer|exists:locations,id',
                'purchase_products' => 'nullable|array',
                'purchase_products.*.id' => [
                    'nullable',
                    'integer',
                    Rule::exists('purchase_products', 'id')->where(function ($query) use ($id) {
                        $query->where('purchase_id', $id);
                    }),
                ],
                'purchase_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_products.*.quantity' => 'nullable|integer',
                'purchase_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_products.*.free_quantity' => 'nullable|numeric',
                'purchase_products.*.expiry_date' => 'nullable|date',
                'purchase_products.*.price' => 'nullable|numeric',
                'purchase_products.*.discount' => 'nullable|numeric',
                'purchase_products.*.discount_percent' => 'nullable|numeric',
                'purchase_products.*.discount_amount' => 'nullable|numeric',
                'purchase_products.*.is_vatable' => 'required',
                'purchase_products.*.field_values' => 'nullable|array',
                'purchase_products.*.field_values.*.id' => [
                    'nullable',
                    'integer',
                    Rule::exists('purchase_product_field_values', 'id'),
                ],
                'purchase_products.*.field_values.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'purchase_products.*.field_values.*.value' => 'required|string|max:255',
                'company_id' => 'integer|exists:companies,id',
            ]);

            $item = DB::transaction(function () use ($validated, $item) {
                // Update the Purchase
                $item->update($validated);

                // Handle purchase products
                if (isset($validated['purchase_products'])) {
                    // Get existing PurchaseProduct IDs
                    $existingProductIds = $item->purchaseProducts()->pluck('id')->toArray();
                    $incomingProductIds = collect($validated['purchase_products'])
                        ->pluck('id')
                        ->filter()
                        ->toArray();

                    // Delete PurchaseProduct records (and their field values via cascade) that are no longer present
                    $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                    PurchaseProduct::whereIn('id', $productsToDelete)->delete();

                    // Process each incoming PurchaseProduct
                    foreach ($validated['purchase_products'] as $purchaseProductData) {
                        $purchaseProductDataFiltered = array_filter($purchaseProductData, function ($key) {
                            return $key !== 'field_values';
                        }, ARRAY_FILTER_USE_KEY);

                        // Handle PurchaseProduct
                        if (isset($purchaseProductData['id'])) {
                            $purchaseProduct = PurchaseProduct::where('id', $purchaseProductData['id'])
                                ->where('purchase_id', $item->id)
                                ->first();

                            if (!$purchaseProduct) {
                                throw new \Exception("PurchaseProduct ID {$purchaseProductData['id']} does not belong to Purchase ID {$item->id}");
                            }

                            $purchaseProduct->update(
                                array_merge($purchaseProductDataFiltered, [
                                    'purchase_id' => $item->id,
                                    'company_id' => $item->company_id,
                                ])
                            );
                        } else {
                            $purchaseProduct = PurchaseProduct::create(
                                array_merge($purchaseProductDataFiltered, [
                                    'purchase_id' => $item->id,
                                    'company_id' => $item->company_id,
                                ])
                            );
                        }

                        // Handle field values for this PurchaseProduct
                        if (isset($purchaseProductData['field_values'])) {
                            // Get existing field value IDs for this PurchaseProduct
                            $existingFieldValueIds = $purchaseProduct->fieldValues()->pluck('id')->toArray();
                            $incomingFieldValueIds = collect($purchaseProductData['field_values'])
                                ->pluck('id')
                                ->filter()
                                ->toArray();

                            // Delete field values that are no longer present
                            $fieldValuesToDelete = array_diff($existingFieldValueIds, $incomingFieldValueIds);
                            PurchaseProductFieldValue::whereIn('id', $fieldValuesToDelete)->delete();

                            // Process each field value
                            foreach ($purchaseProductData['field_values'] as $fieldValueData) {
                                if (isset($fieldValueData['id'])) {
                                    // Update existing field value
                                    $fieldValue = PurchaseProductFieldValue::where('id', $fieldValueData['id'])
                                        ->where('purchase_product_id', $purchaseProduct->id)
                                        ->first();

                                    if (!$fieldValue) {
                                        throw new \Exception("Field value ID {$fieldValueData['id']} does not belong to PurchaseProduct ID {$purchaseProduct->id}");
                                    }

                                    $fieldValue->update([
                                        'product_field_id' => $fieldValueData['product_field_id'],
                                        'value' => $fieldValueData['value'],
                                        'product_id' => $purchaseProduct->product_id,
                                        'company_id' => $purchaseProduct->company_id,
                                        'purchase_product_id' => $purchaseProduct->id,
                                    ]);
                                } else {
                                    // Create new field value
                                    PurchaseProductFieldValue::create([
                                        'product_field_id' => $fieldValueData['product_field_id'],
                                        'value' => $fieldValueData['value'],
                                        'product_id' => $purchaseProduct->product_id,
                                        'company_id' => $purchaseProduct->company_id,
                                        'purchase_product_id' => $purchaseProduct->id,
                                    ]);
                                }
                            }
                        } else {
                            // If no field_values provided, delete all existing field values
                            $purchaseProduct->fieldValues()->delete();
                        }
                    }
                } else {
                    // If no purchase_products provided, delete all existing PurchaseProduct and their field values
                    $item->purchaseProducts()->delete();
                }

                return $item;
            });

            return response()->json([
                'message' => 'Purchase Updated Successfully!!',
                'data' => $item->load('purchaseProducts', 'purchaseProducts.fieldValues'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::error('Purchase not found: ' . $e->getMessage());
            return response()->json(['error' => 'Purchase not found'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error during purchase update: ' . $e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error during purchase update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref_bill_number' => 'required|string|max:255',
            'customer_id' => 'required|exists:customers,id',
            'purchase_bill_number' => [
                'string',
                'max:255',
                Rule::unique('purchases')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id);
                }),
            ],
            'remarks' => 'string|max:255',
            'invoice_date' => 'string|max:255',
            'expiry_date' => 'string|max:255',
            'batch_no' => [
                'string',
                'max:255',
                Rule::unique('purchases')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id);
                }),
            ],
            'discount_percent' => 'numeric',
            'freight_amount' => 'numeric',
            'health_insurance' => 'numeric',
            'balance' => 'numeric',
            'excise_duty' => 'numeric',
            'discount_amount' => 'numeric',
            'discount_after_vat' => 'numeric',
            'roundoff_amount' => 'numeric',
            'payment_type' => 'string|in:cash,bank,credit',
            'discount_amount_vat' => 'numeric',
            'store_id' => 'integer|exists:stores,id',
            'location_id' => 'integer|exists:locations,id',
            'purchase_products' => 'nullable|array',
            'purchase_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_products.*.quantity' => 'nullable|integer',
            'purchase_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_products.*.free_quantity' => 'nullable|numeric',
            'purchase_products.*.expiry_date' => 'nullable|date',
            'purchase_products.*.price' => 'nullable|numeric',
            'purchase_products.*.discount' => 'nullable|numeric',
            'purchase_products.*.discount_percent' => 'nullable|numeric',
            'purchase_products.*.discount_amount' => 'nullable|numeric',
            'purchase_products.*.is_vatable' => 'required',
            'purchase_products.*.field_values' => 'nullable|array',
            'purchase_products.*.field_values.*.product_field_id' => 'required|integer|exists:product_fields,id',
            'purchase_products.*.field_values.*.value' => 'required|string|max:255',
            'company_id' => 'integer|exists:companies,id',
        ]);

        try {
            $item = DB::transaction(function () use ($validated) {
                // Create the Purchase
                $item = Purchase::create($validated);

                // Handle purchase products and their field values
                if (isset($validated['purchase_products'])) {
                    foreach ($validated['purchase_products'] as $purchaseProductData) {
                        // Create the PurchaseProduct
                        $purchaseProduct = $item->purchaseProducts()->create($purchaseProductData);

                        // Create associated field values, if provided
                        if (isset($purchaseProductData['field_values'])) {
                            $fieldValues = array_map(function ($fieldValue) use ($purchaseProduct) {
                                return [
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'product_id' => $purchaseProduct->product_id,
                                    'company_id' => $purchaseProduct->company_id,
                                    'purchase_product_id' => $purchaseProduct->id,
                                ];
                            }, $purchaseProductData['field_values']);

                            // Store field values in PurchaseProductFieldValue
                            PurchaseProductFieldValue::insert($fieldValues);
                        }
                    }
                }

                return $item;
            });

            return response()->json([
                'message' => 'Purchase Created Successfully!!',
                'data' => $item->load('purchaseProducts', 'purchaseProducts.fieldValues'),
            ], 201);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Purchase creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create purchase',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Purchase::with(['purchaseProducts.fieldValues'])->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Purchase::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Purchase deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
