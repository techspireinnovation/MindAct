<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFieldValue;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Product::paginate(10));
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
            ]);

            DB::transaction(function () use ($validated, $id) {
                $product = Product::findOrFail($id);
                $product->update($validated);

                $existingProductIds = $product->productFieldValues()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['field_values'] ?? [])->pluck('id')->filter()->toArray();


                foreach ($validated['field_values'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        // 🛠 Update existing comment
                        $comment = ProductFieldValue::find($data['id']);
                        $comment->update([
                            'product_field_id' => $data['product_field_id'],
                            'value' => $data['value'],
                        ]);
                    } else {
                        // ➕ Create new comment
                        $product->productFieldValues()->create($data);
                    }
                }

                // 🧼 Delete key values not in request
                $fieldsValuesToDelete = array_diff($existingProductIds, $incomingProductIds);
                ProductFieldValue::forceDestroy($fieldsValuesToDelete);

                
            });
            return response()->json(['message' => 'Product Updated']);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean|required',
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
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Product::create($validated);

        if (isset($validated['field_values'])) {
            $item->productFieldValues()->createMany($validated['field_values']);
        }

        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Product::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Product::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Product deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
