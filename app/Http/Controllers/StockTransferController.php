<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;


class StockTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockTransfer::query();
 
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
{
    try {
        $stockTransfer = StockTransfer::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'reference_no' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stock_transfers')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id);
                })->ignore($id),
            ],
            'transfer_to' => 'nullable|string|max:255',
            'document_no' => 'nullable|string|max:255',
            'current_location' => 'nullable|string|max:255',
            'date_ad' => 'nullable|date',
            'transfer_date_bs' => 'nullable|string',
            'document_number' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:255',
            'reasons_for' => 'nullable|string|max:255',
            'product_details' => 'nullable|array',
            'product_details.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.product_name' => 'required_with:product_details|string|max:255',
            'product_details.quantity' => 'required_with:product_details|numeric',
            'product_details.unit' => 'required_with:product_details|string|max:50',
            'product_details.batch_no' => 'required_with:product_details|string|max:255',
            'product_details.price' => 'required_with:product_details|numeric',
            'product_details.amount' => 'required_with:product_details|numeric',
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        $stockTransfer->update($validated);

        return response()->json($stockTransfer->refresh(), 200);
    } catch (ModelNotFoundException $e) {
        \Log::error('StockTransfer not found: ' . $e->getMessage());
        return response()->json(['error' => 'Stock transfer not found'], 404);
    } catch (QueryException $e) {
        \Log::error('QueryException in Stock Transfer::update: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error('Exception in Stock Transfer::update: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_transfers')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id);
                    }),
                ],
                'transfer_to' => 'nullable|string|max:255',
                'document_no' => 'nullable|string|max:255',
                'current_location' => 'nullable|string|max:255',
                'date_ad' => 'nullable|date',
                'transfer_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                
                'remarks' => 'nullable|string|max:255',
                'reasons_for' => 'nullable|string|max:255',
                'product_details' => 'nullable|array',
                'product_details.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.product_name' => 'required_with:product_details|string|max:255',
                'product_details.quantity' => 'required_with:product_details|numeric',
              
                'product_details.unit' => 'required_with:product_details|string|max:50',
                'product_details.batch_no' => 'required_with:product_details|string|max:255',
                'product_details.price' => 'required_with:product_details|numeric',
                'product_details.amount' => 'required_with:product_details|numeric',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $validated = $validator->validated();

            // Convert product_details to JSON string if present
            if (isset($validated['product_details'])) {
                $validated['product_details'] = json_encode($validated['product_details']);
            }

            $item = StockTransfer::create($validated);
            return response()->json($item, 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in StockTransfer::store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in Stock Transfer::store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = StockTransfer::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Adjustment not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = StockTransfer::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Adjustment deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Adjustment not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}
