<?php

namespace App\Http\Controllers;
use App\Models\StockEntry;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationDetail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


class StockReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockReconciliation::query();


        return response()->json($query->paginate(50));
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
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })
                ],
                'document_no' => 'nullable|string|max:255',
                'branch_id' => 'nullable|exists:branches,id',
                'date_ad' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',

                'product_details' => 'nullable|array',
                'product_details.*.id' => 'nullable|integer|exists:stock_reconciliation_details,id',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.available_stock' => 'required_with:product_details|numeric',
                'product_details.*.physical_stock' => 'required_with:product_details|numeric',

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $validated = $validator->validated();
            $stockReconciliation = DB::Transaction(function () use ($validated) {
                $stockReconciliation = StockReconciliation::create($validated);

                if (isset($validated['product_details'])) {

                    foreach ($validated['product_details'] as $detail) {
                        $detail['stock_reconciliation_id'] = $stockReconciliation->id;
                        $detail['company_id'] = $stockReconciliation->company_id;

                        $StockReconcilaitionDetail = StockReconciliationDetail::create($detail);
                    }
                }

                return $stockReconciliation;

            });


            return response()->json([
                'message' => 'Stock Reconciliation created successfully',
                'data' => $stockReconciliation->load('stockReconciliationDetails'),
            ], 201);

        } catch (QueryException $e) {
            \Log::error('Database error in Stock Reconciliation store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);

            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in Stock Reconciliation store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            dd($e->getMessage());
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }



    public function show($id): JsonResponse
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
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })

                        ->ignore($id)
                ],
                'document_no' => 'nullable|string|max:255',
                'branch_id' => 'nullable|exists:branches,id',
                'date_ad' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'product_details' => 'nullable|array',
                'product_details.*.id' => 'nullable|integer|exists:stock_reconciliation_details,id',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.available_stock' => 'required_with:product_details|numeric',
                'product_details.*.physical_stock' => 'required_with:product_details|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            $stockReconciliation = DB::transaction(function () use ($validated, $id) {
                $stockReconciliation = StockReconciliation::findOrFail($id);

                // Update main record (excluding product_details temporarily)
                $updateData = $validated;
                unset($updateData['product_details']);
                $stockReconciliation->update($updateData);

                // Handle product_details if provided
                if (!empty($validated['product_details'])) {
                    $incomingIds = [];

                    foreach ($validated['product_details'] as $detail) {
                        $detail['stock_reconciliation_id'] = $stockReconciliation->id;
                        $detail['company_id'] = $validated['company_id'];

                        if (!empty($detail['id'])) {
                            $existing = StockReconciliationDetail::find($detail['id']);
                            if ($existing) {
                                $existing->update($detail);
                                $incomingIds[] = $existing->id;
                            } else {
                                $new = StockReconciliationDetail::create($detail);
                                $incomingIds[] = $new->id;
                            }
                        } else {
                            $new = StockReconciliationDetail::create($detail);
                            $incomingIds[] = $new->id;
                        }
                    }

                    // Delete removed product detail records
                    $stockReconciliation->stockReconciliationDetails()->whereNotIn('id', $incomingIds)->delete();
                }

                return $stockReconciliation;
            });

            return response()->json([
                'message' => 'Stock Reconciliation updated successfully',
                'data' => $stockReconciliation->load('stockReconciliationDetails'),
            ], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('StockReconciliation not found: ' . $e->getMessage());
            return response()->json(['error' => 'Stock reconciliation not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in StockReconciliation::update: ' . $e->getMessage());
            dd($e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in StockReconciliation::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
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
