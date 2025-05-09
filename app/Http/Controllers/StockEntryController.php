<?php

namespace App\Http\Controllers;

use App\Models\StockEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class StockEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockEntry::query();
    
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
{
    try {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'product_code' => 'required|string|max:255',
            'product_id' => 'nullable|string|exists:products,id', 
            'address' => 'nullable|string',
            'uom' => 'required|numeric|exists:measure_units,id', 
            'batch_no' => 'nullable|string|max:255', 
            'expiry_date' => 'nullable|string|max:255',
            'quantity' => 'nullable|numeric', 
            'rate' => 'nullable|numeric', 
            'amount' => 'nullable|numeric', 
            'location_id' => 'nullable|exists:locations,id', 
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $stockEntry = StockEntry::create($validator->validated());

        return response()->json([
            'message' => 'Stock Entry created successfully',
            'data' => $stockEntry,
        ], 201);

    } catch (QueryException $e) {
        \Log::error('Database error in StockEntry store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        dd($e->getMessage());
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in StockEntry store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}
    
    

public function show($id):JsonResponse
{
    try {
        $item = StockEntry::findOrFail($id);
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
            'product_code' => 'required|string|max:255',
            'product_id' => 'nullable|exists:products,id', 
            'address' => 'nullable|string',
            'uom' => 'required|numeric|exists:measure_units,id',
            'batch_no' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|string|max:255',
            'quantity' => 'nullable|numeric',
            'rate' => 'nullable|numeric',
            'amount' => 'nullable|numeric',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        
        if (empty($data['product_id']) && !empty($data['product_code'])) {
            $product = Product::where('product_code', $data['product_code'])->first();
            if (!$product) {
                return response()->json(['message' => 'Invalid product code. Product not found.'], 404);
            }
            $data['product_id'] = $product->id;
        }

        $stockEntry = StockEntry::findOrFail($id);
        $stockEntry->update($data);

        return response()->json([
            'message' => 'Stock Entry updated successfully',
            'data' => $stockEntry,
        ], 200);

    } catch (QueryException $e) {
        \Log::error('Database error in Stock Entry update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Stock Entry update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}





    public function destroy($id): JsonResponse
    {
        try {
            $item = StockEntry::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Entry deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Entry not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
