<?php

namespace App\Http\Controllers;

use App\Models\ProductField;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductFieldController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ProductField::paginate(10));
    }


    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean|required',
            'company_id' => 'integer|exists:companies,id',
            'type' => 'required|string|in:text,dropdown',
            'values' => 'required_if:type,dropdown|array',
            'values.*' => 'required_if:type,dropdown|string|max:255',
        ]);

        $product_field = ProductField::create($validated);
        return response()->json($product_field, 201);
    }



    public function show($id): JsonResponse
    {
        try {
            $product_field = ProductField::findOrFail($id);
            return response()->json($product_field);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }




    public function update(Request $request, $id): JsonResponse
    {
        try {
            $product_field = ProductField::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'type' => 'required|string|in:text,dropdown',
                'values' => 'required_if:type,dropdown|array',
                'values.*' => 'required_if:type,dropdown|string|max:255',

            ]);
            $product_field->update($validated);
            return response()->json($product_field);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }





    public function destroy($id): JsonResponse
    {
        try {
            $product_field = ProductField::findOrFail($id);
            $product_field->delete();
            return response()->json(['message' => 'Product Field deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}
