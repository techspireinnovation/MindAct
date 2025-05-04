<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Models\ProductList;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
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
                'purchase_bill_number' => ['string',
                                          'max:255',
                                          Rule::unique('purchases')
                                            ->ignore($id) 
                                            ->where(function ($query) use ($request, $item) {
                                             return $query->where('company_id', $request->input('company_id', $item->company_id));
                                    }),
                                  ],
                'remarks' => 'string|max:255',
                'invoice_date' => 'string|max:255',
                'discount_percent' => 'numeric',
                'freight_amount' => 'numeric',
                'batch_no' => ['string',
                              'max:255',
                              Rule::unique('purchases')
                                ->ignore($id) 
                                ->where(function ($query) use ($request, $item) {
                                return $query->where('company_id', $request->input('company_id', $item->company_id));
                           }),
                        ],
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
                'company_id' => 'integer|exists:companies,id'
            ]);

            DB::transaction(function () use ($validated, $id) {
                $product = Purchase::findOrFail($id);
                $product->update($validated);

                $existingProductIds = $product->purchaseProducts()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['purchase_products'] ?? [])->pluck('id')->filter()->toArray();

               
                $fieldsValuesToDelete = array_diff($existingProductIds, $incomingProductIds);
                PurchaseProduct::forceDestroy($fieldsValuesToDelete);

                foreach ($validated['field_values'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        // 🛠 Update existing item
                        $comment = PurchaseProduct::find($data['id']);
                        $comment->update([
                            'purchase_id' => $data['purchase_id'],
                            'value' => $data['value'],
                        ]);
                    } else {
                        $product->productFieldValues()->create($data);
                    }
                }
            });
            return response()->json(['message' => 'Purchase Updated'
               ],201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref_bill_number' => 'required|string|max:255',
            'customer_id' => 'required|exists:customers,id',
            'purchase_bill_number' => ['string',
                                       'max:255',
                                       Rule::unique('purchases')->where(function ($query) use ($request){
                                        return $query->where('company_id', $request->company_id);

                                       }),
                                    ],
            'remarks' => 'string|max:255',
            'invoice_date' => 'string|max:255',
            'expiry_date' => 'string|max:255',
            'batch_no' => ['string',
                           'max:255',
                            Rule::unique('purchases')->where(function ($query) use ($request){
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
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Purchase::create($validated);
        if (isset($validated['purchase_products'])) {
            $item->purchaseProducts()->createMany($validated['purchase_products']);
        }
        return response()->json(['message' => 'Purchse Created Successfully!!',
        'data' => $item->load('purchaseProducts'), 201]);
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
