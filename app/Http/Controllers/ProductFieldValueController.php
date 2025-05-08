<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductFieldValueController extends Controller
{

    public function index(): JsonResponse
    {
        return response()->json(ProductFieldValue::paginate(50));
    }


    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'integer|exists:companies,id',
            'product_id' => 'integer|required|exists:products,id',
            'product_field_id' => 'integer|exists:product_fields,id',
            'value' =>'string|max:255'
           
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()
            ], 422);

        }
        $validated = $validator->validated();

        $field_value = ProductFieldValue::create($validated);
        return response()->json($field_value, 201);
    }



    public function show($id): JsonResponse
    {
        try {
            $field_value = ProductFieldValue::findOrFail($id);
            return response()->json($field_value);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field Value not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }




    public function update(Request $request, $id): JsonResponse
    {
        try {
            $field_value = ProductFieldValue::findOrFail($id);
            $validated = $request->validate([


                'company_id' => 'integer|exists:companies,id',
                'product_field_id' => 'integer|exists:product_fields,id',
                'value' => 'string|max:255'

            ]);

            $field_value->update($validated);
            return response()->json($field_value);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $field_value = ProductFieldValue::findOrFail($id);
            $field_value->delete();
            return response()->json(['message' => 'Product Field Value deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field Value not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}
