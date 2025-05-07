<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\ProductFieldValue;
use App\Models\ProductList;
use App\Models\Purchase;
use Illuminate\Support\Facades\Log;

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

    public function getProducts(Request $request): JsonResponse
    {
        
       $company = $request->company_id;
       $names = Helper::getProductNames($company);
       
    
        return response()->json($names);
    }


    public function getProductDetailsByName(Request $request): JsonResponse
    {
        try{
        
       $productName = $request->input('name');
       $company = $request->company_id;
       
       $productDetails = Helper::getProdutDetailsByName($productName,$company);
       
    
        return response()->json($productDetails);
        }catch(ModelNotFoundEXception $e){
            return response()->json(['errors' => 'Item Not Found!!'],422);
        }catch(QueryNotFoundException $e){
            return response()->json(['errors' => 'Database error occurred!!'],500);
        }catch(\EXception $e){
            dd($e->getMessage());
            return response()->json(['errors' => 'An unexpected error occurred!!'],500);
        }
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
                'discount_type'  => 'nullable|in:percent,amount',
                'discount_value' => 'nullable|numeric',

                'discount_after_vat' => 'numeric',
                'roundoff_amount' => 'numeric',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
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
                'purchase_products.*.quantity' => 'required|integer|min:1',
                'purchase_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_products.*.free_quantity' => 'nullable|numeric',
                'purchase_products.*.expiry_date' => 'nullable|date',
                'purchase_products.*.price' => 'nullable|numeric',
                'purchase_products.*.discount' => 'nullable|numeric',
                'purchase_products.*.discount_percent' => 'nullable|numeric',
                'purchase_products.*.discount_amount' => 'nullable|numeric',
                'purchase_products.*.is_vatable' => 'required|boolean',
                'purchase_products.*.field_values' => 'nullable|array',
                'purchase_products.*.field_values.*' => 'array',
                'purchase_products.*.field_values.*.*.id' => 'nullable|integer|exists:purchase_product_field_values,id',
                'purchase_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'purchase_products.*.field_values.*.*.value' => 'required|string|max:255',
                'company_id' => 'integer|exists:companies,id',
            ]);

            // Log the validated field_values for debugging
            Log::debug('Validated purchase_products field_values', [
                'purchase_products' => $validated['purchase_products'] ?? [],
            ]);

            $item = DB::transaction(function () use ($validated, $item) {
                // Update the Purchase
                $item->update($validated);

                
                $fieldValuesToDelete = [];

                if (isset($validated['purchase_products'])) {
             
                    $existingProductIds = $item->purchaseProducts()->pluck('id')->toArray();
                    $incomingProductIds = collect($validated['purchase_products'])
                        ->pluck('id')
                        ->filter()
                        ->toArray();

                    // Delete PurchaseProduct records that are no longer present
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
                                ->firstOrFail();

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
                            $processedFieldIds = [];

                            // Get existing field value IDs for this purchase product
                            $existingFieldIds = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                                ->pluck('id')
                                ->toArray();

                            // Log existing field IDs
                            Log::debug("Existing field IDs for purchase_product_id {$purchaseProduct->id}", $existingFieldIds);

                            foreach ($purchaseProductData['field_values'] as $quantityIndex => $fieldValueSet) {
                                foreach ($fieldValueSet as $fieldValue) {
                                    // Log the fieldValue for debugging
                                    Log::debug("Processing field_value for purchase_product_id {$purchaseProduct->id}", [
                                        'field_value' => $fieldValue,
                                        'quantity_index' => $quantityIndex,
                                    ]);

                                    if (isset($fieldValue['id']) && !empty($fieldValue['id'])) {
                                        // Update existing field value
                                        $existingValue = PurchaseProductFieldValue::where('id', $fieldValue['id'])
                                            ->where('purchase_product_id', $purchaseProduct->id)
                                            ->withTrashed()
                                            ->first();

                                        if ($existingValue) {
                                            // Restore if soft-deleted
                                            if ($existingValue->trashed()) {
                                                $existingValue->restore();
                                            }
                                            // Update the record
                                            $existingValue->update([
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'quantity_index' => $quantityIndex,
                                                'updated_at' => now(),
                                            ]);
                                            $processedFieldIds[] = $existingValue->id;
                                            Log::debug("Updated field value ID {$fieldValue['id']} for purchase_product_id {$purchaseProduct->id}");
                                        } else {
                                            // Log error and create new record
                                            Log::warning("Field value ID {$fieldValue['id']} not found for purchase_product_id {$purchaseProduct->id}");
                                            $newFieldValue = PurchaseProductFieldValue::create([
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'product_id' => $purchaseProduct->product_id,
                                                'company_id' => $purchaseProduct->company_id,
                                                'purchase_product_id' => $purchaseProduct->id,
                                                'quantity_index' => $quantityIndex,
                                            ]);
                                            $processedFieldIds[] = $newFieldValue->id;
                                            Log::debug("Created new field value ID {$newFieldValue->id} for purchase_product_id {$purchaseProduct->id}");
                                        }
                                    } else {
                                        // Create new field value
                                        $newFieldValue = PurchaseProductFieldValue::create([
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'value' => $fieldValue['value'],
                                            'product_id' => $purchaseProduct->product_id,
                                            'company_id' => $purchaseProduct->company_id,
                                            'purchase_product_id' => $purchaseProduct->id,
                                            'quantity_index' => $quantityIndex,
                                        ]);
                                        $processedFieldIds[] = $newFieldValue->id;
                                        Log::debug("Created new field value ID {$newFieldValue->id} for purchase_product_id {$purchaseProduct->id}");
                                    }
                                }
                            }

                            // Mark unprocessed field values for deletion
                            $unprocessedFieldIds = array_diff($existingFieldIds, $processedFieldIds);
                            if (!empty($unprocessedFieldIds)) {
                                $fieldValuesToDelete[] = [
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'ids' => $unprocessedFieldIds,
                                ];
                            }
                        } else {
                            // If no field_values provided, mark all existing for deletion
                            $existingFieldIds = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                                ->pluck('id')
                                ->toArray();
                            if (!empty($existingFieldIds)) {
                                $fieldValuesToDelete[] = [
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'ids' => $existingFieldIds,
                                ];
                            }
                        }
                    }
                } else {
                    // If no purchase_products provided, delete all existing
                    $item->purchaseProducts()->delete();
                }

                // Perform all field value deletions at the end
                foreach ($fieldValuesToDelete as $deleteSet) {
                    Log::debug("Deleting field values for purchase_product_id {$deleteSet['purchase_product_id']}", $deleteSet['ids']);
                    PurchaseProductFieldValue::where('purchase_product_id', $deleteSet['purchase_product_id'])
                        ->whereIn('id', $deleteSet['ids'])
                        ->delete();
                }

                return $item;
            });

            return response()->json([
                'message' => 'Purchase Updated Successfully!!',
                'data' => $item->load(['purchaseProducts.fieldValues' => function($query) {
                    $query->orderBy('quantity_index')->orderBy('product_field_id');
                }]),
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase not found: ' . $e->getMessage());
            return response()->json(['error' => 'Purchase not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error during purchase update: ' . $e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error during purchase update: ' . $e->getMessage());
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
      'discount_type'  => 'nullable|in:percent,amount',
      'discount_value' => 'nullable|numeric',

        'discount_after_vat' => 'numeric',
        'roundoff_amount' => 'numeric',
        'payment' => 'nullable|array',
        'payment.cash' => 'nullable|numeric|min:0',
        'payment.credit' => 'nullable|numeric|min:0',
        'payment.bank' => 'nullable|numeric|min:0',
        'discount_amount_vat' => 'numeric',
        'store_id' => 'integer|exists:stores,id',
        'location_id' => 'integer|exists:locations,id',
        'purchase_products' => 'nullable|array',
        'purchase_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
        'purchase_products.*.quantity' => 'required|integer|min:1',
        'purchase_products.*.product_id' => 'required|integer|exists:products,id',
        'purchase_products.*.free_quantity' => 'nullable|numeric',
        'purchase_products.*.expiry_date' => 'nullable|date',
        'purchase_products.*.price' => 'nullable|numeric',
        'purchase_products.*.discount' => 'nullable|numeric',
        'purchase_products.*.discount_percent' => 'nullable|numeric',
        'purchase_products.*.discount_amount' => 'nullable|numeric',
        'purchase_products.*.is_vatable' => 'required|boolean',
        'purchase_products.*.field_values' => 'nullable|array',
        'purchase_products.*.field_values.*' => 'array',
        'purchase_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
        'purchase_products.*.field_values.*.*.value' => 'required|string|max:255',
        'company_id' => 'integer|exists:companies,id',
    ]);
    

    try {
        $item = DB::transaction(function () use ($validated) {
            
            $item = Purchase::create($validated);

          
            if (isset($validated['purchase_products'])) {
                foreach ($validated['purchase_products'] as $purchaseProductData) {
                    
                    $purchaseProduct = $item->purchaseProducts()->create($purchaseProductData);

                    
                    if (!empty($purchaseProductData['field_values'])) {
                        $fieldValues = [];
                        foreach ($purchaseProductData['field_values'] as $quantityIndex => $fieldValueSet) {
                            foreach ($fieldValueSet as $fieldValue) {
                                $fieldValues[] = [
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'product_id' => $purchaseProduct->product_id,
                                    'company_id' => $purchaseProduct->company_id,
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'quantity_index' => $quantityIndex,
                                ];
                            }
                        }
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
