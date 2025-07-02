<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
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
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_adjustments')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
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
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.unit_id' => 'required_with:product_details|numeric|max:50',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $stockAdjustment = DB::transaction(function () use ($validated, $id) {
                $stockAdjustment = StockAdjustment::findOrFail($id);

                // Convert product_details to JSON string if present, or set to null
                if (isset($validated['product_details'])) {
                    $validated['product_details'] = json_encode($validated['product_details']);
                } else {
                    $validated['product_details'] = null;
                }

                // Update main record
                $stockAdjustment->update($validated);

                // Handle product details
                // Delete all existing details to replace with new ones
                $stockAdjustment->stockProductDetails()->delete();

                if (isset($validated['product_details'])) {
                    $productDetails = json_decode($validated['product_details'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('Invalid product details format: ' . json_last_error_msg());
                    }

                    $details = [];
                    foreach ($productDetails as $detail) {
                        $detail['stock_adjustment_id'] = $stockAdjustment->id;
                        $detail['company_id'] = $validated['company_id'];
                        $details[] = $detail;
                    }
                    $stockAdjustment->stockProductDetails()->createMany($details);
                }

                return $stockAdjustment;
            });

            return response()->json($stockAdjustment->load('stockProductDetails'), 200);
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
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.diff_stock' => 'required_with:product_details|numeric',
                'product_details.*.actual_stock' => 'required_with:product_details|numeric|min:0',
                'product_details.*.unit_id' => 'required_with:product_details|numeric|max:50',
                'product_details.*.current_stock' => 'required_with:product_details|numeric|min:0',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $item = DB::transaction(function () use ($validated) {
                // Convert product_details to JSON string if present
                if (isset($validated['product_details'])) {
                    $validated['product_details'] = json_encode($validated['product_details']);
                }

                // Create the StockAdjustment record
                $item = StockAdjustment::create($validated);

                // Handle product details
                if (isset($validated['product_details'])) {
                    $productDetails = json_decode($validated['product_details'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('Invalid product details format: ' . json_last_error_msg());
                    }
                    $details = [];
                    foreach ($productDetails as $detail) {
                        $detail['stock_adjustment_id'] = $item->id;
                        $detail['company_id'] = $validated['company_id'];
                        $details[] = $detail;
                    }
                    $item->stockProductDetails()->createMany($details);
                }

                return $item;
            });

            return response()->json($item->load('stockProductDetails'), 201);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in StockAdjustmentController::store: ' . $e->getMessage());
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
            $item = StockAdjustment::with('stockProductDetails')->findOrFail($id);
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
            $item = StockAdjustment::with('stockProductDetails')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Adjustment deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Adjustment not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}
