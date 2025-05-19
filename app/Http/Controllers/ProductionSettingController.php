<?php

namespace App\Http\Controllers;

use App\Models\ProductionSetting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;



class ProductionSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductionSetting::query();
    
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
{
    try {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'date' => 'nullable|string|max:255',
            'document_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('production_settings')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'));
                    })
            ],
            'product_name' => 'nullable|string|max:255',
            'quantity' => 'nullable|numeric',
            'measure_unit_id' => 'nullable|exists:measure_units,id',
            
            'product_details' => 'nullable|array',
            'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.*.product_name' => 'required_with:product_details|string|max:255',
            'product_details.*.quantity' => 'required_with:product_details|numeric',
            'product_details.*.uom' => 'required_with:product_details|exists:measure_units,id',
            'product_details.*.price' => 'required_with:product_details|numeric',
            'product_details.*.amount' => 'required_with:product_details|numeric',
            
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ProductionSetting = ProductionSetting::create($validator->validated());

        return response()->json([
            'message' => 'Production Setting  created successfully',
            'data' => $ProductionSetting,
        ], 201);

    } catch (QueryException $e) {
        \Log::error('Database error in Production Setting store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
       
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Production Setting store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}
    
    

public function show($id):JsonResponse
{
    try {
        $item = ProductionSetting::findOrFail($id);
        return response()->json($item);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Item not found'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}

public function update(Request $request, $id): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'date' => 'nullable|string|max:255',
            'document_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('production_settings')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'));
                    })
                    ->ignore($id)
            ],
            'product_name' => 'nullable|string|max:255',
            'quantity' => 'nullable|numeric',
            'measure_unit_id' => 'nullable|exists:measure_units,id',
            
            'product_details' => 'nullable|array',
            'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.*.product_name' => 'required_with:product_details|string|max:255',
            'product_details.*.quantity' => 'required_with:product_details|numeric',
            'product_details.*.uom' => 'required_with:product_details|exists:measure_units,id',
            'product_details.*.price' => 'required_with:product_details|numeric',
            'product_details.*.amount' => 'required_with:product_details|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $ProductionSetting = ProductionSetting::findOrFail($id);
        
        $ProductionSetting->update($data);

        return response()->json([
            'message' => 'Production Setting updated successfully',
            'data' => $ProductionSetting,
        ], 200);

    } catch (QueryException $e) {
        \Log::error('Database error in Production Setting update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Production Setting update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}



    public function destroy($id): JsonResponse
    {
        try {
            $item = ProductionSetting::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Production Setting deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Production Setting not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
