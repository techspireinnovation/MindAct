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
        return response()->json(SaleProduct::paginate(10));
    }

    

    public function store(Request $request): JsonResponse
{
    try {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'sale_id' => 'required|exists:sales,id',
            'information' => 'required|string|max:255',
            'available_quantity' => 'nullable|numeric',
            'quantity' => 'required|numeric',
            'uom' => 'nullable|exists:measure_units,id',
            'rate' => 'required|numeric',
            'total_items' => 'nullable|integer',
            'discount' => 'nullable|numeric',
            'is_active' => 'required|boolean',
        ]);

        $saleProduct = SaleProduct::create($validated);

        return response()->json([
            'message' => 'Sale Product created successfully',
            'data' => $saleProduct
        ], 201);

    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Item not found'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        dd($e->getMessage());
        return response()->json(['error' => 'Unexpected error occurred.'], 500);
    }
}

public function show($id):JsonResponse
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
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'sale_id' => 'required|exists:sales,id',
            'information' => 'required|string|max:255',
            'available_quantity' => 'nullable|numeric',
            'quantity' => 'required|numeric',
            'uom' => 'nullable|exists:measure_units,id',
            'rate' => 'required|numeric',
            'total_items' => 'nullable|integer',
            'discount' => 'nullable|numeric',
            'is_active' => 'required|boolean',
        ]);

        $saleProduct = SaleProduct::findOrFail($id);
        $saleProduct->update($validated);

        return response()->json([
            'message' => 'Sale Product updated successfully',
            'data' => $saleProduct
        ]);

    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Sale Product not found'], 404);
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
