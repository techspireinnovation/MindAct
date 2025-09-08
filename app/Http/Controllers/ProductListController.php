<?php

namespace App\Http\Controllers;

use App\Models\ProductList;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
class ProductListController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ProductList::paginate(50));
    }
    

    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'measure_unit_id' => 'required|integer|exists:measure_units,id',
                'company_id' => 'required|integer|exists:companies,id',

                'quantity' => 'nullable|integer',
                'barcode' => 'nullable|string|max:255',
                'hs_code' => 'nullable|string|max:255',
                'price' => 'nullable|numeric',
                'discount' => 'nullable|numeric',
                'final_price' => 'nullable|numeric',
                'primary_measure_unit_id' => 'required|integer|exists:measure_units,id'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $item = ProductList::create($validator->validated());
            return response()->json($item, 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }

    }

    public function show($id): JsonResponse
    {
        try {
            $item = ProductList::findorFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }

    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $list = ProductList::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'measure_unit_id' => 'required|integer|exists:measure_units,id',
                'company_id' => 'required|integer|exists:companies,id',

                'quantity' => 'nullable|integer',
                'barcode' => 'nullable|string|max:255',
                'hs_code' => 'nullable|string|max:255',
                'price' => 'nullable|numeric',
                'discount' => 'nullable|numeric',
                'final_price' => 'nullable|numeric',
                'primary_measure_unit_id' => 'required|integer|exists:measure_units,id'


            ]);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $list->update($validator->validated());

            return response()->json($list);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $list = ProductList::findOrFail($id);
            $list->delete();
            return response()->json(['message' => 'Product List deleted!!'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }

    }
            public function productNames(): \Illuminate\Http\JsonResponse
            {
                try {
                    $products = ProductList::with('product:id,name')
                        ->get()
                        ->map(function ($item) {
                            return [
                                'product_id' => $item->product->id,
                                'product_name' => $item->product->name,
                            ];
                        })
                        ->unique('product_id') // optional: avoid duplicate products
                        ->values(); // reset array keys

                    return response()->json([
                        'success' => true,
                        'data' => $products
                    ]);
                } catch (\Exception $e) {
                    return response()->json(['error' => 'An unexpected error occurred'], 500);
                }
            }


}
