<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockAdjustment::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
{
    try {
        $stockAdjustment = StockAdjustment::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'reference_no' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stock_adjustments')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id);
                })->ignore($id),
            ],
            'invoice_date' => 'nullable|date',
            'invoice_date_bs' => 'nullable|string',
            'document_number' => 'nullable|string|max:255',
            'location_id' => 'required|exists:locations,id',
            'remarks' => 'nullable|string|max:255',
            'reasons' => 'nullable|string|max:255',
            'product_details' => 'nullable|array',
            'product_details.product_id' => 'required_with:product_details|integer|exists:products,id',
            'product_details.product_name' => 'required_with:product_details|string|max:255',
            'product_details.diff_stock' => 'required_with:product_details|numeric',
            'product_details.actual_stock' => 'required_with:product_details|numeric|min:0',
            'product_details.unit' => 'required_with:product_details|string|max:50',
            'product_details.current_stock' => 'required_with:product_details|numeric|min:0',
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        $stockAdjustment->update($validated);

        return response()->json($stockAdjustment->refresh(), 200);
    } catch (ModelNotFoundException $e) {
        \Log::error('StockAdjustment not found: ' . $e->getMessage());
        return response()->json(['error' => 'Stock adjustment not found'], 404);
    } catch (QueryException $e) {
        \Log::error('QueryException in StockAdjustmentController::update: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error('Exception in StockAdjustmentController::update: ' . $e->getMessage());
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
                    Rule::unique('stock_adjustments')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id);
                    }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                'location_id' => 'required|exists:locations,id',
                'remarks' => 'nullable|string|max:255',
                'reasons' => 'nullable|string|max:255',
                'product_details' => 'nullable|array',
                'product_details.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.product_name' => 'required_with:product_details|string|max:255',
                'product_details.diff_stock' => 'required_with:product_details|numeric',
                'product_details.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.unit' => 'required_with:product_details|string|max:50',
                'product_details.current_stock' => 'required_with:product_details|numeric|min:0',
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

            $item = StockAdjustment::create($validated);
            return response()->json($item, 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in StockAdjustmentController::store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in StockAdjustmentController::store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = StockAdjustment::findOrFail($id);
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
            $item = StockAdjustment::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Adjustment deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Adjustment not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}
