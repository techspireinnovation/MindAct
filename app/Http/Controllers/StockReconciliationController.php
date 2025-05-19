<?php

namespace App\Http\Controllers;
use App\Models\StockEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\StockReconciliation;


class StockReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockReconciliation::query();
    
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
{
    try {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'date_bs' => 'nullable|string|max:255',
            'reconciliation_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('stock_reconciliations')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'));
                    })
            ],
            'document_no' => 'nullable|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'date_ad' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:255',
           
            'product_details' => 'nullable|array',
            'product_details.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.product_name' => 'required_with:product_details|string|max:255',
            'product_details.available_stock' => 'required_with:product_details|numeric',
            'product_details.physical_stock' => 'required_with:product_details|numeric',
            
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $StockReconciliation = StockReconciliation::create($validator->validated());

        return response()->json([
            'message' => 'Stock Reconciliation created successfully',
            'data' => $StockReconciliation,
        ], 201);

    } catch (QueryException $e) {
        \Log::error('Database error in Stock Reconciliation store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
       
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Stock Reconciliation store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}
    
    

public function show($id):JsonResponse
{
    try {
        $item = StockReconciliation::findOrFail($id);
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
            'date_bs' => 'nullable|string|max:255',
            'reconciliation_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('stock_reconciliations')
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'));
                    })
                    ->ignore($id)
            ],
            'document_no' => 'nullable|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'date_ad' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:255',
           
            'product_details' => 'nullable|array',
            'product_details.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.product_name' => 'required_with:product_details|string|max:255',
            'product_details.available_stock' => 'required_with:product_details|numeric',
            'product_details.physical_stock' => 'required_with:product_details|numeric',
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

        $StockReconciliation = StockReconciliation::findOrFail($id);
        
        $StockReconciliation->update($data);

        return response()->json([
            'message' => 'Stock Reconciliation updated successfully',
            'data' => $StockReconciliation,
        ], 200);

    } catch (QueryException $e) {
        \Log::error('Database error in Stock Reconciliation update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Stock Reconciliation update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}



    public function destroy($id): JsonResponse
    {
        try {
            $item = StockReconciliation::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Reconciliation deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Reconciliation not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
