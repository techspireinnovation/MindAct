<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Helpers\Helper;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseReturnHistory;
use App\Models\ProductList;

use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseReturn::query();
    
        if ($request->has('keywords')) {
            $query->where('ref_bill_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }

// public function getBills(){
//     try{
//         $company = $request->company_id;
//         $bills = Helper::getPurchaseBills($company);
//         return response()->json($bills);
//     }catch(ModelNotFoundException $e){
//             return response()->json(['error','Item Not Found!!'],422);
//     }catch(QueryException $e){
//             return response()->json(['error','Database error occurred!!'],500);
//         }catch(ModelNotFoundException $e){
//             return response()->json(['error','An unexpected error occurred!!'],500);
//         }
//     }

    public function getPurchaseBillNumber(Request $request)
{
    try {
        if (!$request->has('company_id')) {
            return response()->json(['error' => 'Missing required parameter: company_id'], 422);
        }

        $companyId = $request->company_id;

        // Get purchases with at least one product having remaining quantity
        $billNumbers = Purchase::where('company_id', $companyId)
            ->whereHas('purchaseProducts', function ($query) {
                $query->whereRaw('quantity - COALESCE((
                    SELECT SUM(purchase_product_returns.quantity)
                    FROM purchase_product_returns
                    WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                    AND purchase_product_returns.deleted_at IS NULL
                ), 0) > 0');
            })
            ->pluck('purchase_bill_number');

        return response()->json($billNumbers);
    } catch (\Exception $e) {
        \Log::error('Error in getPurchaseBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}

    public function getRefBillNumber(Request $request)
{
    try {
        if (!$request->has('company_id')) {
            return response()->json(['error' => 'Missing required parameter: company_id'], 422);
        }

        $companyId = $request->company_id;

        // Get purchases with at least one product having remaining quantity
        $billNumbers = Purchase::where('company_id', $companyId)
            ->whereHas('purchaseProducts', function ($query) {
                $query->whereRaw('quantity - COALESCE((
                    SELECT SUM(purchase_product_returns.quantity)
                    FROM purchase_product_returns
                    WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                    AND purchase_product_returns.deleted_at IS NULL
                ), 0) > 0');
            })
            ->pluck('ref_bill_number');

        return response()->json($billNumbers);
    } catch (\Exception $e) {
        \Log::error('Error in getRefBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}
    
public function getPurchaseByBillNumber(Request $request)
{
    try {
        if (!$request->has('purchase_bill_number') || !$request->has('company_id')) {
            return response()->json(['error' => 'Missing required parameters.'], 422);
        }

        $purchase = Purchase::where('company_id', $request->company_id)
            ->where('purchase_bill_number', $request->purchase_bill_number)
            ->with([
                'purchaseProducts' => function ($query) {
                    $query->whereRaw('quantity - COALESCE((
                        SELECT SUM(purchase_product_returns.quantity)
                        FROM purchase_product_returns
                        WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                        AND purchase_product_returns.deleted_at IS NULL
                    ), 0) > 0')
                    ->with([
                        'fieldValues.productField',
                        'purchaseProductReturns' => function ($subQuery) {
                            $subQuery->whereNull('deleted_at');
                        }
                    ]);
                }
            ])
            ->first();

        if (!$purchase) {
            return response()->json(['error' => 'Purchase not found'], 404);
        }

        // If no products with remaining quantity, return not found
        if (empty($purchase->purchaseProducts)) {
            return response()->json(['error' => 'No available products for this purchase'], 404);
        }

        // Transform the field_values into nested array format, excluding already returned field_values
        $purchaseData = $purchase->toArray();
        foreach ($purchaseData['purchase_products'] as &$product) {
            // Calculate remaining quantity
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product['id'])
                ->whereNull('deleted_at')
                ->sum('quantity');
            $product['remaining_quantity'] = $product['quantity'] - $totalReturned;

            // Get the quantity indices of already returned field_values
            $returnedQuantityIndices = [];
            if (!empty($product['purchase_product_returns'])) {
                $returnIds = array_column($product['purchase_product_returns'], 'id');
                $returnedQuantityIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                    ->whereNull('deleted_at')
                    ->pluck('quantity_index')
                    ->toArray();
                $returnedQuantityIndices = array_unique($returnedQuantityIndices);
            }

            // Filter field_values to exclude those already returned
            $groupedFieldValues = [];
            foreach ($product['field_values'] as $fieldValue) {
                $quantityIndex = $fieldValue['quantity_index'];
                if (in_array($quantityIndex, $returnedQuantityIndices)) {
                    continue;
                }
                if (!isset($groupedFieldValues[$quantityIndex])) {
                    $groupedFieldValues[$quantityIndex] = [];
                }
                $groupedFieldValues[$quantityIndex][] = [
                    'product_field_id' => $fieldValue['product_field_id'],
                    'name' => $fieldValue['product_field']['name'] ?? null,
                    'value' => $fieldValue['value']
                ];
            }
            $product['field_values'] = array_values($groupedFieldValues);

            // Clean up purchase_product_returns from the response
            unset($product['purchase_product_returns']);
        }

        // Filter out products with no field_values (all units returned)
        $purchaseData['purchase_products'] = array_filter($purchaseData['purchase_products'], function ($product) {
            return !empty($product['field_values']);
        });

        // If no products remain after filtering, return not found
        if (empty($purchaseData['purchase_products'])) {
            return response()->json(['error' => 'No available products for this purchase'], 404);
        }

        return response()->json(['data' => $purchaseData]);
    } catch (QueryException $e) {
        \Log::error('Database error in getPurchaseByBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'A database error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in getPurchaseByBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}


public function getPurchaseByRefBillNumber(Request $request)
{
    try {
        if (!$request->has('ref_bill_number') || !$request->has('company_id')) {
            return response()->json(['error' => 'Missing required parameters.'], 422);
        }

        $purchase = Purchase::where('company_id', $request->company_id)
            ->where('ref_bill_number', $request->ref_bill_number)
            ->with([
                'purchaseProducts' => function ($query) {
                    $query->whereRaw('quantity - COALESCE((
                        SELECT SUM(purchase_product_returns.quantity)
                        FROM purchase_product_returns
                        WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                        AND purchase_product_returns.deleted_at IS NULL
                    ), 0) > 0')
                    ->with(['fieldValues.productField']);
                }
            ])
            ->first();

        if (!$purchase) {
            return response()->json(['error' => 'Purchase not found'], 404);
        }

        // If no products with remaining quantity, return not found
        if (empty($purchase->purchaseProducts)) {
            return response()->json(['error' => 'No available products for this purchase'], 404);
        }

        // Transform the field_values into nested array format, excluding already returned field_values
        $purchaseData = $purchase->toArray();
        foreach ($purchaseData['purchase_products'] as &$product) {
            // Calculate remaining quantity
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product['id'])
                ->whereNull('deleted_at')
                ->sum('quantity');
            $product['remaining_quantity'] = $product['quantity'] - $totalReturned;

            // Get the quantity indices of already returned field_values
            $returnedQuantityIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', 
                PurchaseProductReturn::where('purchase_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->pluck('id'))
                ->pluck('quantity_index')
                ->toArray();
            $returnedQuantityIndices = array_unique($returnedQuantityIndices);

            // Filter field_values to exclude those already returned
            $groupedFieldValues = [];
            foreach ($product['field_values'] as $fieldValue) {
                $quantityIndex = $fieldValue['quantity_index'];
                if (in_array($quantityIndex, $returnedQuantityIndices)) {
                    continue;
                }
                if (!isset($groupedFieldValues[$quantityIndex])) {
                    $groupedFieldValues[$quantityIndex] = [];
                }
                $groupedFieldValues[$quantityIndex][] = [
                    'product_field_id' => $fieldValue['product_field_id'],
                    'name' => $fieldValue['product_field']['name'] ?? null,
                    'value' => $fieldValue['value']
                ];
            }
            $product['field_values'] = array_values($groupedFieldValues);
        }

        // Filter out products with no field_values (all units returned)
        $purchaseData['purchase_products'] = array_filter($purchaseData['purchase_products'], function ($product) {
            return !empty($product['field_values']);
        });

        // If no products remain after filtering, return not found
        if (empty($purchaseData['purchase_products'])) {
            return response()->json(['error' => 'No available products for this purchase'], 404);
        }

        return response()->json(['data' => $purchaseData]);
    } catch (QueryException $e) {
        \Log::error('Database error in getPurchaseByRefBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'A database error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in getPurchaseByRefBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}



    public function getProductNames(Request $request)
    {
        try {
            $company = $request->company_id;
            $productNames = Helper::getPurchaseProductNames($company);
            
            
    
            return response()->json($productNames);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found!!'], 422);
        } catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'Database error occurred!!'], 422);
        } catch (\Exception $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 422);
        }
    }

    public function getPurchaseProductDetails(Request $request)
    {
        try {
            $name = $request->input('purchase_product_name');
             $company = $request->company_id;
            $productDetails = Helper::getPurchaseProductDetails($name,$company);
            
            
    
            return response()->json($productDetails);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found!!'], 422);
        } catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'Database error occurred!!'], 422);
        } catch (\Exception $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 422);
        }
    }



public function store(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'purchase_id' => 'required|integer|exists:purchases,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'customer_contact' => 'nullable|string|max:255',
            'purchase_number' => 'nullable|string|max:255',
            'purchase_bill_number' => 'required|string|max:255',
            'invoice_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:255',
            'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
            'store_id' => 'required|integer|exists:stores,id',
            'location_id' => 'required|integer|exists:locations,id',
            'company_id' => 'required|integer|exists:companies,id',
            'balance' => 'nullable|numeric',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'sub_total_before_discount' => 'nullable|numeric|min:0',
            'non_taxable_amount' => 'nullable|numeric|min:0',
            'taxable_amount' => 'nullable|numeric|min:0',
            'excise_duty' => 'nullable|numeric|min:0',
            'health_insurance' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_after_vat' => 'nullable|numeric|min:0',
            'roundoff_amount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric|min:0',
            'payment' => 'nullable|array',
            'payment.cash' => 'nullable|numeric|min:0',
            'payment.credit' => 'nullable|numeric|min:0',
            'payment.bank' => 'nullable|numeric|min:0',
            'return_entire_batch' => 'nullable|boolean',
            'purchase_return_products' => 'required_unless:return_entire_batch,true|array|min:1',
            'purchase_return_products.*.purchase_product_id' => [
                'required',
                'integer',
                Rule::exists('purchase_products', 'id')->where(function ($query) use ($request) {
                    $query->where('purchase_id', $request->input('purchase_id'));
                }),
            ],
            'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_return_products.*.product_name' => 'required|string|max:255',
            'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
            'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
            'purchase_return_products.*.quantity' => 'required|numeric|min:0',
            'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
            'purchase_return_products.*.price' => 'nullable|numeric|min:0',
            'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.is_vatable' => 'required|boolean',
            'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_return_products.*.expiry_date' => 'nullable|date',
            'purchase_return_products.*.field_values' => 'nullable|array',
            'purchase_return_products.*.field_values.*' => 'array|min:1',
            'purchase_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
            'purchase_return_products.*.field_values.*.*.value' => 'required|string|max:255',
            'purchase_return_products.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Fetch purchase
        $purchase = Purchase::findOrFail($validated['purchase_id']);

        // Validate purchase_bill_number consistency
        if ($validated['purchase_bill_number'] !== $purchase->purchase_bill_number) {
            return response()->json([
                'error' => 'Purchase bill number must match the purchase record\'s bill number'
            ], 422);
        }

        // Auto-fetch products for entire batch
        if ($validated['return_entire_batch'] ?? false) {
            $validated['purchase_return_products'] = $purchase->purchaseProducts()->get()->map(function ($product) {
                $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('free_quantity');
                $quantityToReturn = $product->quantity - $totalReturned;
                $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($field) {
                        return [
                            'product_field_id' => $field->product_field_id,
                            'value' => $field->value,
                            'quantity_index' => $field->quantity_index
                        ];
                    })->toArray();
                })->values()->toArray();
                // Only include field_values for available units
                $fieldValues = array_slice($fieldValues, 0, $quantityToReturn);
                return [
                    'purchase_product_id' => $product->id,
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'purchase_product_code' => $product->product_code,
                    'customer_id' => $product->customer_id,
                    'quantity' => $quantityToReturn,
                    'free_quantity' => $product->free_quantity - $totalFreeReturned,
                    'price' => $product->price,
                    'discount_percent' => $product->discount_percent,
                    'discount_amount' => $product->discount_amount,
                    'amount' => $product->amount,
                    'is_vatable' => $product->is_vatable,
                    'measure_unit_id' => $product->measure_unit_id,
                    'expiry_date' => $product->expiry_date,
                    'field_values' => $fieldValues
                ];
            })->filter(function ($product) {
                return $product['quantity'] > 0 || $product['free_quantity'] > 0;
            })->toArray();
        }

        // Validate quantities and field_values
        foreach ($validated['purchase_return_products'] as $index => $productData) {
            $originalProduct = PurchaseProduct::where('id', $productData['purchase_product_id'])
                ->where('purchase_id', $validated['purchase_id'])
                ->firstOrFail();

            // Check available quantity
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->sum('quantity');
            $availableQuantity = $originalProduct->quantity - $totalReturned;
            if ($productData['quantity'] > $availableQuantity) {
                return response()->json([
                    'error' => "Return quantity {$productData['quantity']} exceeds available quantity {$availableQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Check available free quantity
            $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->sum('free_quantity');
            $availableFreeQuantity = $originalProduct->free_quantity - $totalFreeReturned;
            if (($productData['free_quantity'] ?? 0) > $availableFreeQuantity) {
                return response()->json([
                    'error' => "Free return quantity {$productData['free_quantity']} exceeds available free quantity {$availableFreeQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Validate field_values count
            if (!($validated['return_entire_batch'] ?? false)) {
                $hasFieldValues = $originalProduct->fieldValues()->exists();
                $requiredFieldValues = $hasFieldValues ? $productData['quantity'] : 0;
                if ($hasFieldValues && (!isset($productData['field_values']) || count($productData['field_values']) !== $requiredFieldValues)) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($productData['field_values']) ? count($productData['field_values']) : 0) . ") must match quantity ({$productData['quantity']}) for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Validate field_values completeness and accuracy
                if (isset($productData['field_values'])) {
                    $existingFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $productData['purchase_product_id'])
                        ->get()
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->pluck('value', 'product_field_id')->toArray();
                        })->toArray();
                    $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', 
                        PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                            ->whereNull('deleted_at')
                            ->pluck('id'))
                        ->pluck('quantity_index')
                        ->toArray();
                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        if (!isset($existingFieldValues[$quantityIndex]) || in_array($quantityIndex, $returnedIndices)) {
                            return response()->json([
                                'error' => "Invalid or already returned quantity_index {$quantityIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        $providedFields = array_column($fieldValueSet, 'value', 'product_field_id');
                        if ($providedFields != $existingFieldValues[$quantityIndex]) {
                            return response()->json([
                                'error' => "Field values for quantity_index {$quantityIndex} do not match existing values for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        // Ensure consistent quantity_index within set
                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['quantity_index']) && $fieldValue['quantity_index'] !== $quantityIndex) {
                                return response()->json([
                                    'error' => "Inconsistent quantity_index in field_values set {$arrayIndex} for product ID {$productData['product_id']} at index {$index}"
                                ], 422);
                            }
                        }
                    }
                }
            }

            // Validate field_values uniqueness
            if (isset($productData['field_values'])) {
                foreach ($productData['field_values'] as $setIndex => $fieldValueSet) {
                    $fieldIds = array_column($fieldValueSet, 'product_field_id');
                    if (count($fieldIds) !== count(array_unique($fieldIds))) {
                        return response()->json([
                            'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }
                }
            }
        }

        Log::debug('Validated purchase return data', [
            'purchase_id' => $validated['purchase_id'],
            'purchase_return_products' => $validated['purchase_return_products'] ?? [],
        ]);

        $item = DB::transaction(function () use ($validated) {
            $purchaseReturnData = array_filter($validated, function ($key) {
                return !in_array($key, ['purchase_return_products', 'return_entire_batch']);
            }, ARRAY_FILTER_USE_KEY);

            $item = PurchaseReturn::create($purchaseReturnData);

            $returnValue = 0;
            foreach ($validated['purchase_return_products'] as $productData) {
                $productDataFiltered = array_filter($productData, function ($key) {
                    return $key !== 'field_values';
                }, ARRAY_FILTER_USE_KEY);

                $purchaseProductReturn = $item->purchaseReturnProducts()->create(
                    array_merge($productDataFiltered, [
                        'company_id' => $item->company_id,
                    ])
                );

                if (isset($productData['field_values'])) {
                    $fieldValues = [];
                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        foreach ($fieldValueSet as $fieldValue) {
                            $fieldValues[] = [
                                'purchase_return_product_id' => $purchaseProductReturn->id,
                                'product_field_id' => $fieldValue['product_field_id'],
                                'value' => $fieldValue['value'],
                                'product_id' => $purchaseProductReturn->product_id,
                                'company_id' => $purchaseProductReturn->company_id,
                                'quantity_index' => $quantityIndex,
                                'created_at' => now(),
                                'updated_at' => now()
                            ];
                        }
                    }
                    Log::debug('Recording PurchaseReturnProductFieldValue', ['field_values' => $fieldValues]);
                    PurchaseReturnProductFieldValue::insert($fieldValues);
                }

                $returnValue += ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
            }

            $purchase = Purchase::findOrFail($item->purchase_id);
            $purchase->balance -= $returnValue;
            $purchase->save();

            PurchaseReturnHistory::create([
                'purchase_return_id' => $item->id,
                'action' => 'created',
                'data' => $validated
            ]);

            return $item;
        });

        return response()->json([
            'message' => 'Purchase Return Created Successfully',
            'data' => $item->load(['purchaseReturnProducts.fieldValues' => function ($query) {
                $query->orderBy('quantity_index')->orderBy('product_field_id');
            }])
        ], 201);
    } catch (ModelNotFoundException $e) {
        Log::error('Purchase or related record not found: ' . $e->getMessage());
        return response()->json(['error' => 'Purchase or related record not found'], 404);
    } catch (\Exception $e) {
        Log::error('Error creating purchase return: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 422);
    }
}
     
    public function update(Request $request, $id): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'purchase_id' => 'required|integer|exists:purchases,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'customer_contact' => 'nullable|string|max:255',
            'purchase_number' => 'nullable|string|max:255',
            'purchase_bill_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('purchase_returns', 'purchase_bill_number')->ignore($id)->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->input('company_id'));
                }),
            ],
            'invoice_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:255',
            'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
            'store_id' => 'required|integer|exists:stores,id',
            'location_id' => 'required|integer|exists:locations,id',
            'company_id' => 'required|integer|exists:companies,id',
            'balance' => 'nullable|numeric',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'sub_total_before_discount' => 'nullable|numeric|min:0',
            'non_taxable_amount' => 'nullable|numeric|min:0',
            'taxable_amount' => 'nullable|numeric|min:0',
            'excise_duty' => 'nullable|numeric|min:0',
            'health_insurance' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_after_vat' => 'nullable|numeric|min:0',
            'roundoff_amount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric|min:0',
            'payment' => 'nullable|array',
            'payment.cash' => 'nullable|numeric|min:0',
            'payment.credit' => 'nullable|numeric|min:0',
            'payment.bank' => 'nullable|numeric|min:0',
            'return_entire_batch' => 'nullable|boolean',
            'purchase_return_products' => 'required_unless:return_entire_batch,true|array|min:1',
            'purchase_return_products.*.id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_product_returns', 'id')->where(function ($query) use ($id) {
                    $query->where('purchase_return_id', $id);
                }),
            ],
            'purchase_return_products.*.purchase_product_id' => [
                'required',
                'integer',
                Rule::exists('purchase_products', 'id')->where(function ($query) use ($request) {
                    $query->where('purchase_id', $request->input('purchase_id'));
                }),
            ],
            'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_return_products.*.product_name' => 'required|string|max:255',
            'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
            'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
            'purchase_return_products.*.quantity' => 'required|numeric|min:0',
            'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
            'purchase_return_products.*.price' => 'nullable|numeric|min:0',
            'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.is_vatable' => 'required|boolean',
            'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_return_products.*.expiry_date' => 'nullable|date',
            'purchase_return_products.*.field_values' => 'nullable|array',
            'purchase_return_products.*.field_values.*' => 'array|min:1',
            'purchase_return_products.*.field_values.*.*.id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_return_product_field_values', 'id')->where(function ($query) use ($request, $id) {
                    $index = array_key_first($request->input('purchase_return_products'));
                    $purchaseProductReturnId = $request->input("purchase_return_products.$index.id");
                    if ($purchaseProductReturnId) {
                        $query->where('purchase_return_product_id', $purchaseProductReturnId);
                    }
                }),
            ],
            'purchase_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
            'purchase_return_products.*.field_values.*.*.value' => 'required|string|max:255',
            'purchase_return_products.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Fetch purchase
        $purchase = Purchase::findOrFail($validated['purchase_id']);

        // Validate purchase_bill_number consistency
        if ($validated['purchase_bill_number'] && $validated['purchase_bill_number'] !== $purchase->purchase_bill_number) {
            return response()->json([
                'error' => 'Purchase bill number must match the purchase record\'s bill number'
            ], 422);
        }

        // Auto-fetch products for entire batch
        if ($validated['return_entire_batch'] ?? false) {
            $validated['purchase_return_products'] = $purchase->purchaseProducts()->get()->map(function ($product) {
                $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('free_quantity');
                $quantityToReturn = $product->quantity - $totalReturned;
                $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($field) {
                        return [
                            'product_field_id' => $field->product_field_id,
                            'value' => $field->value,
                            'quantity_index' => $field->quantity_index
                        ];
                    })->toArray();
                })->values()->toArray();
                $fieldValues = array_slice($fieldValues, 0, $quantityToReturn);
                return [
                    'purchase_product_id' => $product->id,
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'purchase_product_code' => $product->product_code,
                    'customer_id' => $product->customer_id,
                    'quantity' => $quantityToReturn,
                    'free_quantity' => $product->free_quantity - $totalFreeReturned,
                    'price' => $product->price,
                    'discount_percent' => $product->discount_percent,
                    'discount_amount' => $product->discount_amount,
                    'amount' => $product->amount,
                    'is_vatable' => $product->is_vatable,
                    'measure_unit_id' => $product->measure_unit_id,
                    'expiry_date' => $product->expiry_date,
                    'field_values' => $fieldValues
                ];
            })->filter(function ($product) {
                return $product['quantity'] > 0 || $product['free_quantity'] > 0;
            })->toArray();
        }

        // Validate quantities and field_values
        foreach ($validated['purchase_return_products'] as $index => $productData) {
            $originalProduct = PurchaseProduct::where('id', $productData['purchase_product_id'])
                ->where('purchase_id', $validated['purchase_id'])
                ->firstOrFail();

            // Check available quantity
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->where('id', '!=', $productData['id'] ?? 0)
                ->sum('quantity');
            $availableQuantity = $originalProduct->quantity - $totalReturned;
            if ($productData['quantity'] > $availableQuantity) {
                return response()->json([
                    'error' => "Return quantity {$productData['quantity']} exceeds available quantity {$availableQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Check available free quantity
            $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->where('id', '!=', $productData['id'] ?? 0)
                ->sum('free_quantity');
            $availableFreeQuantity = $originalProduct->free_quantity - $totalFreeReturned;
            if (($productData['free_quantity'] ?? 0) > $availableFreeQuantity) {
                return response()->json([
                    'error' => "Free return quantity {$productData['free_quantity']} exceeds available free quantity {$availableFreeQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Validate field_values count
            if (!($validated['return_entire_batch'] ?? false)) {
                $hasFieldValues = $originalProduct->fieldValues()->exists();
                $requiredFieldValues = $hasFieldValues ? $productData['quantity'] : 0;
                if ($hasFieldValues && (!isset($productData['field_values']) || count($productData['field_values']) !== $requiredFieldValues)) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($productData['field_values']) ? count($productData['field_values']) : 0) . ") must match quantity ({$productData['quantity']}) for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Validate field_values completeness and accuracy
                if (isset($productData['field_values'])) {
                    $existingFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $productData['purchase_product_id'])
                        ->get()
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->pluck('value', 'product_field_id')->toArray();
                        })->toArray();
                    $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', 
                        PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                            ->whereNull('deleted_at')
                            ->where('id', '!=', $productData['id'] ?? 0)
                            ->pluck('id'))
                        ->pluck('quantity_index')
                        ->toArray();
                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        if (!isset($existingFieldValues[$quantityIndex]) || in_array($quantityIndex, $returnedIndices)) {
                            return response()->json([
                                'error' => "Invalid or already returned quantity_index {$quantityIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        $providedFields = array_column($fieldValueSet, 'value', 'product_field_id');
                        if ($providedFields != $existingFieldValues[$quantityIndex]) {
                            return response()->json([
                                'error' => "Field values for quantity_index {$quantityIndex} do not match existing values for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        // Ensure consistent quantity_index within set
                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['quantity_index']) && $fieldValue['quantity_index'] !== $quantityIndex) {
                                return response()->json([
                                    'error' => "Inconsistent quantity_index in field_values set {$arrayIndex} for product ID {$productData['product_id']} at index {$index}"
                                ], 422);
                            }
                        }
                    }
                }
            }

            // Validate field_values uniqueness
            if (isset($productData['field_values'])) {
                foreach ($productData['field_values'] as $setIndex => $fieldValueSet) {
                    $fieldIds = array_column($fieldValueSet, 'product_field_id');
                    if (count($fieldIds) !== count(array_unique($fieldIds))) {
                        return response()->json([
                            'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }
                }
            }
        }

        Log::debug('Validated purchase return update data', [
            'purchase_id' => $validated['purchase_id'],
            'purchase_return_products' => $validated['purchase_return_products'] ?? [],
        ]);

        $item = DB::transaction(function () use ($validated, $id) {
            $item = PurchaseReturn::findOrFail($id);

            $purchaseReturnData = array_filter($validated, function ($key) {
                return !in_array($key, ['purchase_return_products', 'return_entire_batch']);
            }, ARRAY_FILTER_USE_KEY);
            $item->update($purchaseReturnData);

            if (isset($validated['purchase_return_products'])) {
                $existingProductIds = $item->purchaseReturnProducts()->withTrashed()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['purchase_return_products'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                if (!empty($productsToDelete)) {
                    Log::debug("Soft deleting purchase_return_products", ['ids' => $productsToDelete]);
                    PurchaseProductReturn::whereIn('id', $productsToDelete)->delete();
                }

                $returnValue = 0;
                foreach ($validated['purchase_return_products'] as $productData) {
                    $productDataFiltered = array_filter($productData, function ($key) {
                        return $key !== 'field_values';
                    }, ARRAY_FILTER_USE_KEY);

                    if (isset($productData['id'])) {
                        $purchaseProductReturn = PurchaseProductReturn::where('id', $productData['id'])
                            ->where('purchase_return_id', $item->id)
                            ->withTrashed()
                            ->firstOrFail();

                        if ($purchaseProductReturn->trashed()) {
                            $purchaseProductReturn->restore();
                            Log::debug("Restored purchase_return_product_id {$purchaseProductReturn->id}");
                        }

                        $purchaseProductReturn->update(
                            array_merge($productDataFiltered, [
                                'purchase_return_id' => $item->id,
                                'company_id' => $item->company_id,
                            ])
                        );
                    } else {
                        $purchaseProductReturn = $item->purchaseReturnProducts()->create(
                            array_merge($productDataFiltered, [
                                'purchase_return_id' => $item->id,
                                'company_id' => $item->company_id,
                            ])
                        );
                    }

                    if (isset($productData['field_values'])) {
                        $processedFieldIds = [];

                        $existingFieldIds = PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                            ->withTrashed()
                            ->pluck('id')
                            ->toArray();

                        Log::debug("Existing field IDs for purchase_return_product_id {$purchaseProductReturn->id}", ['ids' => $existingFieldIds]);

                        foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                            if (count($fieldValueSet) > 0) {
                                $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                                foreach ($fieldValueSet as $fieldValue) {
                                    Log::debug("Processing field_value for purchase_return_product_id {$purchaseProductReturn->id}", [
                                        'field_value' => $fieldValue,
                                        'quantity_index' => $quantityIndex,
                                    ]);

                                    if (isset($fieldValue['id']) && !empty($fieldValue['id'])) {
                                        $existingValue = PurchaseReturnProductFieldValue::where('id', $fieldValue['id'])
                                            ->where('purchase_return_product_id', $purchaseProductReturn->id)
                                            ->withTrashed()
                                            ->first();

                                        if ($existingValue) {
                                            if ($existingValue->trashed()) {
                                                $existingValue->restore();
                                                Log::debug("Restored field value ID {$fieldValue['id']} for purchase_return_product_id {$purchaseProductReturn->id}");
                                            }
                                            $existingValue->update([
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'quantity_index' => $quantityIndex,
                                                'updated_at' => now(),
                                            ]);
                                            $processedFieldIds[] = $existingValue->id;
                                            Log::debug("Updated field value ID {$fieldValue['id']} for purchase_return_product_id {$purchaseProductReturn->id}");
                                        } else {
                                            Log::warning("Field value ID {$fieldValue['id']} not found for purchase_return_product_id {$purchaseProductReturn->id}");
                                            $newFieldValue = PurchaseReturnProductFieldValue::create([
                                                'purchase_return_product_id' => $purchaseProductReturn->id,
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'product_id' => $purchaseProductReturn->product_id,
                                                'company_id' => $purchaseProductReturn->company_id,
                                                'quantity_index' => $quantityIndex,
                                                'created_at' => now(),
                                                'updated_at' => now(),
                                            ]);
                                            $processedFieldIds[] = $newFieldValue->id;
                                            Log::debug("Created new field value ID {$newFieldValue->id} for purchase_return_product_id {$purchaseProductReturn->id}");
                                        }
                                    } else {
                                        $newFieldValue = PurchaseReturnProductFieldValue::create([
                                            'purchase_return_product_id' => $purchaseProductReturn->id,
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'value' => $fieldValue['value'],
                                            'product_id' => $purchaseProductReturn->product_id,
                                            'company_id' => $purchaseProductReturn->company_id,
                                            'quantity_index' => $quantityIndex,
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                        $processedFieldIds[] = $newFieldValue->id;
                                        Log::debug("Created new field value ID {$newFieldValue->id} for purchase_return_product_id {$purchaseProductReturn->id}");
                                    }
                                }
                            }
                        }

                        $unprocessedFieldIds = array_diff($existingFieldIds, $processedFieldIds);
                        if (!empty($unprocessedFieldIds)) {
                            Log::debug("Soft deleting field values for purchase_return_product_id {$purchaseProductReturn->id}", ['ids' => $unprocessedFieldIds]);
                            PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                                ->whereIn('id', $unprocessedFieldIds)
                                ->delete();
                        }
                    } else {
                        $existingFieldIds = PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                            ->withTrashed()
                            ->pluck('id')
                            ->toArray();
                        if (!empty($existingFieldIds)) {
                            Log::debug("Soft deleting all field values for purchase_return_product_id {$purchaseProductReturn->id}", ['ids' => $existingFieldIds]);
                            PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                                ->whereIn('id', $existingFieldIds)
                                ->delete();
                        }
                    }

                    $returnValue += ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                }
            } else {
                $existingProductIds = $item->purchaseReturnProducts()->withTrashed()->pluck('id')->toArray();
                if (!empty($existingProductIds)) {
                    Log::debug("Soft deleting all purchase_return_products for purchase_return_id {$item->id}", ['ids' => $existingProductIds]);
                    PurchaseProductReturn::whereIn('id', $existingProductIds)->delete();
                }
            }

            // Update purchase balance
            $purchase = Purchase::findOrFail($item->purchase_id);
            $previousReturnValue = PurchaseProductReturn::where('purchase_return_id', $item->id)
                ->whereNull('deleted_at')
                ->sum(DB::raw('(quantity * price) - discount_amount'));
            $newReturnValue = $returnValue;
            $purchase->balance += $previousReturnValue - $newReturnValue;
            $purchase->save();

            PurchaseReturnHistory::create([
                'purchase_return_id' => $item->id,
                'action' => 'updated',
                'data' => $validated
            ]);

            return $item;
        });

        return response()->json([
            'message' => 'Purchase Return Updated Successfully',
            'data' => $item->load(['purchaseReturnProducts.fieldValues' => function ($query) {
                $query->orderBy('quantity_index')->orderBy('product_field_id');
            }])
        ], 200);
    } catch (ModelNotFoundException $e) {
        Log::error('Purchase return not found: ' . $e->getMessage());
        return response()->json(['error' => 'Purchase return not found'], 404);
    } catch (QueryException $e) {
        Log::error('Database error during purchase return update: ' . $e->getMessage());
        return response()->json(['error' => 'A database error occurred'], 500);
    } catch (\Exception $e) {
        Log::error('Unexpected error during purchase return update: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 422);
    }
}

    

    public function show($id): JsonResponse
    {
        try {
            $item = PurchaseReturn::with(['PurchaseProductReturn'])->findOrFail($id);
            return response()->json($item->load('PurchaseProductReturn'));
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
            $item = PurchaseReturn::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Purchase Return deleted']);
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
