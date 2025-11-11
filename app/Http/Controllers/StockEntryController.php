<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Auth;
use DB;
use Illuminate\Support\Facades\Log;
use App\Models\PurchaseStockProduct;
use App\Models\StockEntry;
use App\Models\StockMain;
use App\Models\Branch;
use App\Models\StockProductFieldValue;
use App\Models\PurchaseStockProductFieldValue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


class StockEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockMain::query();

        $stockMains = $query->paginate(50);
        $transformed = $stockMains->getCollection()->map(function ($stockMain) {
            return [
                'id' => $stockMain->id,
                'code' => $stockMain->code,
                'name' => $stockMain->name,


            ];
        });

        $stockMains->setCollection($transformed);

        return response()->json($stockMains);
    }



    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'code' => [
                    'required',
                    'string',
                    Rule::unique('stock_mains')->whereNull('deleted_at'),
                ],
                'stock_entries' => 'required|array',
                'entry_code' => 'nullable|string|unique:stock_entries,entry_code',

                'stock_entries.*.product_code' => 'required|string|max:255',
                'stock_entries.*.product_name' => 'nullable|string|max:255',
                'stock_entries.*.product_id' => 'nullable|string|exists:products,id',
                'stock_entries.*.branch_id' => 'nullable|numeric|exists:branches,id',
                'stock_entries.*.purchase_type' => 'required|string',
                'stock_entries.*.uom' => 'required|numeric|exists:measure_units,id',
                'stock_entries.*.batch_no' => 'nullable|string|max:255',
                'stock_entries.*.expiry_date' => 'nullable|string|max:255',
                'stock_entries.*.quantity' => 'nullable|numeric',
                'stock_entries.*.rate' => 'nullable|numeric',
                'stock_entries.*.amount' => 'nullable|numeric',
                'stock_entries.*.location_id' => 'nullable|exists:locations,id',
                'stock_entries.*.field_values' => 'nullable|array',

                'stock_entries.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'stock_entries.*.field_values.*.*.value' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $createdEntries = [];

            DB::transaction(function () use ($request, &$createdEntries) {
                // Create StockMain
                $stockMain = StockMain::create([
                    'name' => $request->name,
                    'code' => $request->code,
                    'company_id' => $request->company_id,
                ]);

                foreach ($request->stock_entries as $entry) {
                    $entry['company_id'] = $request->company_id;
                    $entry['stock_main_id'] = $stockMain->id;

                    // Create StockEntry
                    $stockEntry = StockEntry::create($entry);

                    // Map uom to measure_unit_id for PurchaseStockProduct
                    $entry['stock_product_id'] = $stockEntry->id;
                    $entry['measure_unit_id'] = $entry['uom'];

                    // Create PurchaseStockProduct
                    $purchaseStock = PurchaseStockProduct::create($entry);

                    // Save field values
                    if (!empty($entry['field_values']) && is_array($entry['field_values'])) {
                        foreach ($entry['field_values'] as $quantityIndex => $fieldGroup) {
                            foreach ($fieldGroup as $fieldValue) {
                                StockProductFieldValue::create([
                                    'stock_product_id' => $stockEntry->id,
                                    'company_id' => $entry['company_id'],
                                    'product_id' => $stockEntry->product_id,
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'quantity_index' => $quantityIndex,
                                ]);

                                PurchaseStockProductFieldValue::create([
                                    'stock_product_id' => $stockEntry->id,
                                    'purchase_stock_product_id' => $purchaseStock->id,
                                    'company_id' => $entry['company_id'],
                                    'product_id' => $stockEntry->product_id,
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'quantity_index' => $quantityIndex,
                                ]);
                            }
                        }
                    }

                    $createdEntries[] = $stockEntry->load('fieldValues');
                }
            });

            return response()->json([
                'message' => 'Stock entries created successfully',
                'data' => $createdEntries,
            ], 201);

        } catch (QueryException $e) {
            \Log::error('Database error in StockEntry store', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in StockEntry store', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }





    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'code' => [
                    'required',
                    'string',
                    Rule::unique('stock_mains')->ignore($id)->whereNull('deleted_at'),
                ],
                'stock_entries' => 'required|array',
                'entry_code' => 'nullable|string|unique:stock_entries,entry_code,' . $id,
                'stock_entries.*.product_code' => 'required|string|max:255',
                'stock_entries.*.product_name' => 'nullable|string|max:255',
                'stock_entries.*.product_id' => 'nullable|string|exists:products,id',
                'stock_entries.*.branch_id' => 'nullable|numeric|exists:branches,id',
                'stock_entries.*.purchase_type' => 'required|string',
                'stock_entries.*.uom' => 'required|numeric|exists:measure_units,id',
                'stock_entries.*.batch_no' => 'nullable|string|max:255',
                'stock_entries.*.expiry_date' => 'nullable|string|max:255',
                'stock_entries.*.quantity' => 'nullable|numeric',
                'stock_entries.*.rate' => 'nullable|numeric',
                'stock_entries.*.amount' => 'nullable|numeric',
                'stock_entries.*.location_id' => 'nullable|exists:locations,id',
                'stock_entries.*.field_values' => 'nullable|array',
                'stock_entries.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'stock_entries.*.field_values.*.*.value' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $createdEntries = [];

            DB::transaction(function () use ($request, $id, &$createdEntries) {
                $stockMain = StockMain::findOrFail($id);
                $stockMain->update([
                    'name' => $request->name,
                    'code' => $request->code,
                    'company_id' => $request->company_id,
                ]);

                // Delete old entries and their related data
                $oldEntries = StockEntry::where('stock_main_id', $stockMain->id)->pluck('id')->toArray();
                if (!empty($oldEntries)) {
                    StockProductFieldValue::whereIn('stock_product_id', $oldEntries)->delete();
                    PurchaseStockProductFieldValue::whereIn('stock_product_id', $oldEntries)->delete();
                    PurchaseStockProduct::whereIn('stock_product_id', $oldEntries)->delete();
                    StockEntry::whereIn('id', $oldEntries)->delete();
                }

                foreach ($request->stock_entries as $entry) {
                    $entry['company_id'] = $request->company_id;
                    $entry['stock_main_id'] = $stockMain->id;

                    $stockEntry = StockEntry::create($entry);

                    $entry['stock_product_id'] = $stockEntry->id;
                    $entry['measure_unit_id'] = $entry['uom']; // for PurchaseStockProduct

                    $purchaseStock = PurchaseStockProduct::create($entry);

                    // Save field values
                    if (!empty($entry['field_values']) && is_array($entry['field_values'])) {
                        foreach ($entry['field_values'] as $quantityIndex => $fieldGroup) {
                            foreach ($fieldGroup as $fieldValue) {
                                StockProductFieldValue::create([
                                    'stock_product_id' => $stockEntry->id,
                                    'company_id' => $entry['company_id'],
                                    'product_id' => $stockEntry->product_id,
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'quantity_index' => $quantityIndex,
                                ]);

                                PurchaseStockProductFieldValue::create([
                                    'stock_product_id' => $stockEntry->id,
                                    'purchase_stock_product_id' => $purchaseStock->id,
                                    'company_id' => $entry['company_id'],
                                    'product_id' => $stockEntry->product_id,
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'quantity_index' => $quantityIndex,
                                ]);
                            }
                        }
                    }

                    $createdEntries[] = $stockEntry->load('fieldValues');
                }
            });

            return response()->json([
                'message' => 'Stock entries updated successfully',
                'data' => $createdEntries,
            ], 200);

        } catch (QueryException $e) {
            \Log::error('Database error in StockEntry update', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in StockEntry update', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            $branchData = Branch::on('mysql')->where('id', $branchId)->firstOrFail();

            // Check if the branch is main
            $isMainBranch = strtolower($branchData->branch_type ?? '') === 'main';

            if ($isMainBranch) {
                $item = StockMain::where('company_id', $companyId)->with('fieldValues')->get();
            } else {
                $item = StockMain::where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->with('fieldValues')
                    ->get();
            }



            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $item = StockMain::with('stockEntries.fieldValues')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Entry deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Entry not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}
