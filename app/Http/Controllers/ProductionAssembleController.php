<?php

namespace App\Http\Controllers;

use App\Models\ProductionAssemble;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionAssembleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductionAssemble::query();
    
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
{
    try {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'production_id' => 'nullable',
            'product_name' => 'nullable|string|max:255',
            'product_location_id' => 'nullable|exists:locations,id',
            'measure_unit_id' => 'nullable|exists:measure_units,id',
            'product_quantity' => 'nullable|numeric',
            'production_date' => 'nullable|string|max:255',
            'production_no' => 'nullable|string|max:255',
        
            'document_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('production_assembles')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'))
                        ->whereNull('deleted_at');
                    })
            ],
            'batch_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('production_assembles')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'));
                    })
            ],
            
            'product_details' => 'nullable|array',
            'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.*.product_name' => 'required_with:product_details|string|max:255',
            'product_details.*.quantity' => 'required_with:product_details|numeric',
            'product_details.*.damage_lost' => 'required_with:product_details|numeric',
            'product_details.*.rate' => 'required_with:product_details|numeric',
            'product_details.*.amount' => 'required_with:product_details|numeric',
            
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ProductionAssemble = ProductionAssemble::create($validator->validated());

        return response()->json([
            'message' => 'Production Assemble  created successfully',
            'data' => $ProductionAssemble,
        ], 201);

    } catch (QueryException $e) {
        \Log::error('Database error in Production Assemble store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
       
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Production Assemble store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}
    
    

public function show($id):JsonResponse
{
    try {
        $item = ProductionAssemble::findOrFail($id);
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
            'production_id' => 'nullable',
            'product_name' => 'nullable|string|max:255',
            'product_location_id' => 'nullable|exists:locations,id',
            'measure_unit_id' => 'nullable|exists:measure_units,id',
            'product_quantity' => 'nullable|numeric',
            'production_date' => 'nullable|string|max:255',
            'production_no' => 'nullable|string|max:255',
           
            'document_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('production_assembles')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'))
                        ->whereNull('deleted_at');
                    })
                    ->ignore($id)
            ],
            'batch_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('production_assembles')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'))
                        ->whereNull('deleted_at');
                    })
                    ->ignore($id)
            ],
            
            'product_details' => 'nullable|array',
            'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.*.product_name' => 'required_with:product_details|string|max:255',
            'product_details.*.quantity' => 'required_with:product_details|numeric',
            'product_details.*.damage_lost' => 'required_with:product_details|numeric',
            'product_details.*.rate' => 'required_with:product_details|numeric',
            'product_details.*.amount' => 'required_with:product_details|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $ProductionAssemble = ProductionAssemble::findOrFail($id);
        
        $ProductionAssemble->update($data);

        return response()->json([
            'message' => 'Production Assemble updated successfully',
            'data' => $ProductionAssemble,
        ], 200);

    } catch (QueryException $e) {
         
        \Log::error('Database error in Production Assemble update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        // Log the error with sensitive data excluded
        \Log::error('Unexpected error in Production Assemble update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}



    public function destroy($id): JsonResponse
    {
        try {
            $item = ProductionAssemble::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Production Assemble deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Production Assemble not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
