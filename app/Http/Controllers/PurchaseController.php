<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Models\ProductList;
use App\Models\Purchase;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Purchase::paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'category_id' => 'integer|exists:product_categories,id',
                'brand_id' => 'integer|exists:brands,id',
                'measure_unit_id' => 'integer|exists:measure_units,id',
                'purchase_rate' => 'numeric',
                'purchase_rate_vat' => 'numeric',
                'retail_sales_price' => 'numeric',
                'retail_sales_price_vat' => 'numeric',
                'retail_sales_price_profit_percent' => 'numeric',
                'wholesales_price' => 'numeric',
                'wholesales_price_vat' => 'numeric',
                'wholesales_price_profit_percent' => 'numeric',
                'is_vatable' => 'boolean',
                'product_type_id' => 'integer|exists:product_types,id',
                'location_id' => 'integer|exists:locations,id',
                'field_values' => 'required|array',
                'field_values.*.product_field_id' => 'integer|exists:product_fields,id',
                'field_values.*.value' => 'required|string|max:255',
                'product_list' => 'required|array',
                'product_list.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_list.*.quantity' => 'nullable|integer',
                'product_list.*.barcode' => 'nullable|string|max:255',
                'product_list.*.hs_code' => 'nullable|string|max:255',
                'product_list.*.price' => 'nullable|numeric',
                'product_list.*.discount' => 'nullable|numeric',
                'product_list.*.final_price' => 'nullable|numeric',
                'product_list.*.primary_measure_unit_id' => 'required|integer|exists:measure_units,id',
            ]);

            DB::transaction(function () use ($validated, $id) {
                $product = Product::findOrFail($id);
                $product->update($validated);

                $existingProductIds = $product->productFieldValues()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['field_values'] ?? [])->pluck('id')->filter()->toArray();

                // 🧼 Delete key values not in request
                $fieldsValuesToDelete = array_diff($existingProductIds, $incomingProductIds);
                ProductFieldValue::forceDestroy($fieldsValuesToDelete);

                foreach ($validated['field_values'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        // 🛠 Update existing item
                        $comment = ProductFieldValue::find($data['id']);
                        $comment->update([
                            'product_field_id' => $data['product_field_id'],
                            'value' => $data['value'],
                        ]);
                    } else {
                        $product->productFieldValues()->create($data);
                    }
                }

                $existingProductIds = $product->productList()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['product_list'] ?? [])->pluck('id')->filter()->toArray();

                // 🧼 Delete key values not in request
                $fieldsValuesToDelete = array_diff($existingProductIds, $incomingProductIds);
                ProductList::forceDestroy($fieldsValuesToDelete);

                foreach ($validated['product_list'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        // 🛠 Update existing item
                        $comment = ProductList::find($data['id']);
                        $comment->update([
                            'product_id' => $data['product_id'],
                            'value' => $data['value'],
                        ]);
                    } else {
                        $product->productList()->create($data);
                    }
                }
            });
            return response()->json(['message' => 'Product Updated']);

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref_bill_number' => 'required|string|max:255',
            'purchase_bill_number' => 'string|max:255',
            'remarks' => 'string|max:255',
            'invoice_date' => 'string|max:255',
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
            'purchase_products.*.price' => 'nullable|numeric',
            'purchase_products.*.discount' => 'nullable|numeric',
            'purchase_products.*.discount_percent' => 'nullable|numeric',
            'purchase_products.*.discount_amount' => 'nullable|numeric',
            'purchase_products.*.is_vatable' => 'required',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Purchase::create($validated);


        if (isset($validated['purchase_products'])) {
            $item->purchaseProducts()->createMany($validated['purchase_products']);
        }

        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Purchase::with(['purchaseProducts'])->findOrFail($id);
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
            return response()->json(['message' => 'Product Type deleted']);
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
