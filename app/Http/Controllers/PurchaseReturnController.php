<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Models\ProductList;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturn;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PurchaseReturn::paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {

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
                'purchase_return_products' => 'nullable|array',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.quantity' => 'nullable|integer',
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric',
                'purchase_return_products.*.price' => 'nullable|numeric',
                'purchase_return_products.*.discount' => 'nullable|numeric',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric',
                'purchase_return_products.*.is_vatable' => 'required',
                'company_id' => 'integer|exists:companies,id'
            ]);

            DB::transaction(function () use ($validated, $id) {
                $product = PurchaseReturn::findOrFail($id);
                $product->update($validated);

                $existingProductIds = $product->purchaseReturnProducts()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['purchase_return_products'] ?? [])->pluck('id')->filter()->toArray();

                // 🧼 Delete key values not in request
                $fieldsValuesToDelete = array_diff($existingProductIds, $incomingProductIds);
                PurchaseProductReturn::forceDestroy($fieldsValuesToDelete);

                foreach ($validated['purchase_return_products'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        // 🛠 Update existing item
                        $comment = PurchaseProductReturn::find($data['id']);
                        $comment->update([
                            'purchase_return_id' => $data['purchase_return_id'],
                            'value' => $data['value'],
                        ]);
                    } else {
                        $product->purchaseReturnProducts()->create($data);
                    }
                }
            });
            return response()->json(['message' => 'Purchase Return Updated']);

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
            'purchase_return_products' => 'nullable|array',
            'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_return_products.*.quantity' => 'nullable|integer',
            'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_return_products.*.free_quantity' => 'nullable|numeric',
            'purchase_return_products.*.price' => 'nullable|numeric',
            'purchase_return_products.*.discount' => 'nullable|numeric',
            'purchase_return_products.*.discount_percent' => 'nullable|numeric',
            'purchase_return_products.*.discount_amount' => 'nullable|numeric',
            'purchase_return_products.*.is_vatable' => 'required',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = PurchaseReturn::create($validated);
        if (isset($validated['purchase_return_products'])) {
            $item->purchaseReturnProducts()->createMany($validated['purchase_return_products']);
        }
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = PurchaseReturn::with(['purchaseReturnProducts'])->findOrFail($id);
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
