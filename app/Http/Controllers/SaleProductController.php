<?php

namespace App\Http\Controllers;

use App\Models\SaleProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class SaleProductController extends Controller
{

    public function index(): JsonResponse
    {

        return response()->json(SaleProduct::paginate(50));
    }



    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'sale_id' => 'required|exists:sales,id',
                'code' => 'nullable|string|max:255',
                'name' => 'required|string|max:255|unique:sale_products,name',
                'measure_unit_id' => 'nullable|exists:measure_units,id',
                'quantity' => 'nullable|numeric',
                'free_quantity' => 'nullable|numeric',
                'price' => 'nullable|numeric',
                'discount_percent' => 'nullable|numeric',
                'discount_amount' => 'nullable|numeric',
                'is_vatable' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $saleProduct = SaleProduct::create($validated);

            return response()->json([
                'message' => 'Sale product created successfully',
                'data' => $saleProduct
            ], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = SaleProduct::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    /**
     * Update an existing SaleProduct.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'sale_id' => 'required|exists:sales,id',
                'code' => 'nullable|string|max:255',
                'name' => 'required|string|max:255|unique:sale_products,name,' . $id,
                'measure_unit_id' => 'nullable|exists:measure_units,id',
                'quantity' => 'nullable|numeric',
                'free_quantity' => 'nullable|numeric',
                'price' => 'nullable|numeric',
                'discount_percent' => 'nullable|numeric',
                'discount_amount' => 'nullable|numeric',
                'is_vatable' => 'boolean'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $saleProduct = SaleProduct::findOrFail($id);
            $validated = $validator->validated();
            $saleProduct->update($validated);

            return response()->json([
                'message' => 'Sale product updated successfully',

                'data' => $saleProduct
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Sale product not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $item = SaleProduct::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Sale Product deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}