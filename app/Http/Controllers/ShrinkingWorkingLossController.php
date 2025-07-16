<?php

namespace App\Http\Controllers;

use App\Models\ShrinkingWorkingLoss;
use App\Models\PurchaseProduct;
use App\Models\MeasureUnit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShrinkingWorkingLossController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ShrinkingWorkingLoss::query();


        return response()->json($query->paginate(10));
    }


    public function getProductDetailsforShrinkingWorkingLoss(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'date_from' => 'nullable|string|max:255',
                'date_to' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $productId = $request->input('product_id');
            $dateFrom = $request->filled('date_from') ? $request->input('date_from') : null;
            $dateTo = $request->filled('date_to') ? $request->input('date_to') : null;

            // Check for previously processed date_to in ShrinkingWorkingLoss
            $latestProcessed = ShrinkingWorkingLoss::where('product_id', $productId)
                ->whereNotNull('date_from')
                ->whereNotNull('date_to')
                ->orderBy('date_to', 'desc')
                ->first();

            // Adjust date_from to avoid overlap with previously processed ranges
            if ($latestProcessed && $dateFrom) {
                $latestDateTo = \Carbon\Carbon::parse($latestProcessed->date_to);
                $requestedDateFrom = \Carbon\Carbon::parse($dateFrom);
                if ($requestedDateFrom->lte($latestDateTo)) {
                    $dateFrom = $latestDateTo->addDay()->toDateString();
                }
            }

            // Fetch PurchaseProduct records
            $purchasedProducts = PurchaseProduct::where('product_id', $productId)
                ->whereHas('purchase', function ($query) use ($dateFrom, $dateTo) {
                    if ($dateFrom) {
                        $query->whereDate('invoice_date_bs', '>=', $dateFrom);
                    }
                    if ($dateTo) {
                        $query->whereDate('invoice_date_bs', '<=', $dateTo);
                    }
                })
                ->with('purchase')
                ->get();

            // Get all measure_unit_ids used in PurchaseProduct
            $measureUnitIds = $purchasedProducts->pluck('measure_unit_id')->filter()->unique()->toArray();

            // Fetch MeasureUnit details
            $measureUnits = MeasureUnit::whereIn('id', $measureUnitIds)
                ->get(['id', 'quantity'])
                ->keyBy('id');

            // Enrich PurchaseProduct records with quantity_in_pieces
            $enrichedProducts = $purchasedProducts->map(function ($product) use ($measureUnits) {
                $data = $product->toArray();
                $measureUnitId = $product->measure_unit_id;
                $quantity = $product->quantity ?? 0;
                $freeQuantity = $product->free_quantity ?? 0;

            $data['purchase_bill_number'] = $product->purchase->purchase_bill_number ?? null;
            $data['ref_bill_number'] = $product->purchase->ref_bill_number ?? null;

                // Calculate quantity_in_pieces
                if (isset($measureUnits[$measureUnitId]) && !is_null($measureUnits[$measureUnitId]->quantity)) {
                    $measureUnitQuantity = $measureUnits[$measureUnitId]->quantity;

                    $quantityRegular = floor($quantity);
                    $quantityRegularDecimal = (float) $quantity;
                    $quantityRegularInDecimal = explode('.', (string) $quantity);
                    $decimalRegularDigits = isset($quantityRegularInDecimal[1]) ? (float) $quantityRegularInDecimal[1] : 0;
                    $quantityRegularInt = ($quantityRegular * $measureUnitQuantity) + $decimalRegularDigits;

                    $freeQuantityRegular = floor($freeQuantity);
                    $quantityFreeDecimal = (float) $freeQuantity;
                    $quantityFreeInDecimal = explode('.', (string) $freeQuantity);
                    $decimalFreeDigits = isset($quantityFreeInDecimal[1]) ? (float) $quantityFreeInDecimal[1] : 0;
                    $quantityFreeInt = ($freeQuantityRegular * $measureUnitQuantity) + $decimalFreeDigits;

                    $data['quantity_in_pieces'] = $quantityRegularInt + $quantityFreeInt;
                } else {
                    // Fallback to quantity + free_quantity
                    $data['quantity_in_pieces'] = $quantity + $freeQuantity;
                }

                return $data;
            });

            return response()->json([
                'message' => 'Data Retrieved Successfully !!',
                'data' => $enrichedProducts
            ], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('Item not Found in getProductDetailsforShrinkingWorkingLoss', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error in getProductDetailsforShrinkingWorkingLoss', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getProductDetailsforShrinkingWorkingLoss', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'date_from' => 'nullable|string|max:255',
                'date_to' => 'nullable|string|max:255',
                'product_id' => 'nullable|exists:products,id',
                'shrinking_loss_percent' => 'nullable|numeric',
                'working_loss_percent' => 'nullable|numeric',
                'internal_loss_percent' => 'nullable|numeric',
                'adjustment_ref_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('shrinking_working_losses')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })
                ],
                'product_details' => 'nullable|array',
                'product_details.*.purchase_date' => 'required_with:product_details',
                'product_details.*.purchase_bill_number' => 'required_with:product_details',
                'product_details.*.ref_bill_number' => 'required_with:product_details',
                'product_details.*.purchase_quantity' => 'required_with:product_details',
                'product_details.*.shrinking_loss' => 'required_with:product_details',
                'product_details.*.working_loss' => 'required_with:product_details',
                'product_details.*.internal_loss' => 'required_with:product_details',
                'product_details.*.total_loss' => 'required_with:product_details',
                'total_purchase_quantity' => 'nullable|numeric',
                'total_loss_quantity' => 'nullable|numeric'

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $ShrinkingWorkingLoss = ShrinkingWorkingLoss::create($validator->validated());

            return response()->json([
                'message' => 'Shrinking Working Loss created successfully',
                'data' => $ShrinkingWorkingLoss,
            ], 201);

        } catch (QueryException $e) {
            \Log::error('Database error in Shrinking Working Loss  store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);

            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in Shrinking Working Loss store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }



    public function show($id): JsonResponse
    {
        try {
            $item = ShrinkingWorkingLoss::findOrFail($id);
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
                'date_from' => 'nullable|string|max:255',
                'date_to' => 'nullable|string|max:255',
                'product_id' => 'nullable|exists:products,id',
                'shrinking_loss_percent' => 'nullable|numeric',
                'working_loss_percent' => 'nullable|numeric',
                'internal_loss_percent' => 'nullable|numeric',
                'adjustment_ref_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('shrinking_working_losses')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })

                ],
                'product_details' => 'nullable|array',
                'product_details.*.purchase_date' => 'required_with:product_details',
                'product_details.*.purchase_bill_number' => 'required_with:product_details',
                'product_details.*.ref_bill_number' => 'required_with:product_details',
                'product_details.*.purchase_quantity' => 'required_with:product_details',
                'product_details.*.shrinking_loss' => 'required_with:product_details',
                'product_details.*.working_loss' => 'required_with:product_details',
                'product_details.*.internal_loss' => 'required_with:product_details',
                'product_details.*.total_loss' => 'required_with:product_details',
                'total_purchase_quantity' => 'nullable|numeric',
                'total_loss_quantity' => 'nullable|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            $ShrinkingWorkingLoss = ShrinkingWorkingLoss::findOrFail($id);

            $ShrinkingWorkingLoss->update($data);

            return response()->json([
                'message' => 'Shrinking Working Loss updated successfully',
                'data' => $ShrinkingWorkingLoss,
            ], 200);

        } catch (QueryException $e) {

            \Log::error('Database error in Shrinking Working Loss update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            // Log the error with sensitive data excluded
            \Log::error('Unexpected error in Shrinking Working Loss update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = ShrinkingWorkingLoss::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Shrinking Working Loss deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Shrinking Working Loss not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
