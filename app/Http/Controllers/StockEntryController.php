<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Auth;
use DB;
use App\Models\PurchaseStockProduct;
use App\Models\StockEntry;
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
        $query = StockEntry::query();

        $stockEntries = $query->paginate(50);
        $transformed = $stockEntries->getCollection()->map(function ($stockEntry) {
            return [
                'id' => $stockEntry->id,
                'name' => $stockEntry->name,
                'product_id' => $stockEntry->product_id,
                'product_code' => $stockEntry->product_code,
                'product_name' => $stockEntry->product_name,
                'uom' => $stockEntry->uom,
                'batch_no' => $stockEntry->batch_no,
                'expiry_date' => $stockEntry->expiry_date,
                'quantity' => $stockEntry->quantity,
                'rate' => $stockEntry->rate,
                'amount' => $stockEntry->amount,
                'location_id' => $stockEntry->location_id,

                'location_name' => optional($stockEntry->location)->name,

            ];
        });

        $stockEntries->setCollection($transformed);

        return response()->json($stockEntries);
    }


    public function store(Request $request): JsonResponse
    {
   

        try {
            $validator = Validator::make($request->all(), [
                'stock_entries' => 'required|array',

                'stock_entries.*.product_code' => 'required|string|max:255',
                'stock_entries.*.product_name' => 'nullable|string|max:255',
                'stock_entries.*.product_id' => 'nullable|string|exists:products,id',
                'stock_entries.*.branch_id' => 'nullable|numeric|exists:branches,id',
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
                'stock_entries.*.field_values.*.*.quantity_index' => 'required|numeric|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $createdEntries = [];

            foreach ($request->stock_entries as $entry) {
                $entry['company_id'] = $request->company_id;

                // Create stock entry
                $stockEntry = StockEntry::create($entry);

                $entry['stock_product_id'] = $stockEntry->id;

                $purchaseStock = PurchaseStockProduct::create($entry);

                // If there are field values, save them
                if (!empty($entry['field_values']) && is_array($entry['field_values'])) {
                    foreach ($entry['field_values'] as $fieldGroup) {
                        foreach ($fieldGroup as $fieldValue) {
                            StockProductFieldValue::create([
                                'stock_product_id' => $stockEntry->id,
                                'company_id' => $entry['company_id'],
                                'product_id' => $stockEntry->product_id,
                                'product_field_id' => $fieldValue['product_field_id'],
                                'value' => $fieldValue['value'],
                                'quantity_index' => $fieldValue['quantity_index'],
                            ]);

                            PurchaseStockProductFieldValue::create([
                                'stock_product_id' => $stockEntry->id,
                                // 'purchase_product_id' => $purchaseStock->id,
                                'company_id' => $entry['company_id'],
                                'product_id' => $stockEntry->product_id,
                                'product_field_id' => $fieldValue['product_field_id'],
                                'value' => $fieldValue['value'],
                                'quantity_index' => $fieldValue['quantity_index'],
                            ]);
                        }
                    }
                }

                $createdEntries[] = $stockEntry->load('fieldValues'); // Optional: load relationship
            }

            return response()->json([
                'message' => 'Stock entries created successfully',
                'data' => $createdEntries,
            ], 201);

        } catch (QueryException $e) {
          

            \Log::error('Database error in StockEntry store', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            // dd($e->getMessage());
            \Log::error('Unexpected error in StockEntry store', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }



    public function update(Request $request): JsonResponse
    {
        try {
            // Validation rules (same as store)
            $validator = Validator::make($request->all(), [
                'stock_entries' => 'required|array',
                'stock_entries.*.id' => 'nullable|exists:stock_entries,id',
                'stock_entries.*.product_code' => 'required|string|max:255',
                'stock_entries.*.product_name' => 'nullable|string|max:255',
                'stock_entries.*.product_id' => 'nullable|exists:products,id',
                'stock_entries.*.branch_id' => 'nullable|numeric|exists:branches,id',
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
                'stock_entries.*.field_values.*.*.quantity_index' => 'required|numeric|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Get authenticated user and their branch
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Assume user has a branch_id column or BelongsTo relationship
            $userBranchId = $user->branch_id; // Adjust based on your User model
            $companyId = $request->company_id ?? $user->company_id; // Fallback to user's company_id

            // Check if user belongs to the main branch (branch_id = 1)
            $isMainBranch = $userBranchId == 1;

            $updatedEntries = [];

            DB::transaction(function () use ($request, $companyId, $userBranchId, $isMainBranch, &$updatedEntries) {
                foreach ($request->stock_entries as $entry) {
                    // Resolve product_id from product_code if not provided
                    if (empty($entry['product_id']) && !empty($entry['product_code'])) {
                        $product = Product::where('product_code', $entry['product_code'])->first();
                        if (!$product) {
                            throw new \Exception("Invalid product code `{$entry['product_code']}`. Product not found.");
                        }
                        $entry['product_id'] = $product->id;
                    }
                    $entry['company_id'] = $companyId;

                    // Restrict updates/creations to user's branch unless main branch
                    if (!$isMainBranch && isset($entry['branch_id']) && $entry['branch_id'] != $userBranchId) {
                        throw new \Exception("You are not authorized to process stock entries for branch ID {$entry['branch_id']}.");
                    }

                    // Update or create stock entry
                    if (!empty($entry['id'])) {
                        // Update existing stock entry
                        $stockEntry = StockEntry::where('id', $entry['id'])
                            ->where('company_id', $companyId)
                            ->firstOrFail();

                        // Restrict updates to user's branch unless main branch
                        if (!$isMainBranch && $stockEntry->branch_id != $userBranchId) {
                            throw new \Exception("You are not authorized to update stock entry ID {$entry['id']} for branch ID {$stockEntry->branch_id}.");
                        }

                        $stockEntry->update($entry);

                        // Delete existing field values to avoid duplicates
                        StockProductFieldValue::where('stock_product_id', $stockEntry->id)->delete();
                        PurchaseStockProductFieldValue::where('stock_product_id', $stockEntry->id)->delete();

                        // Update or create PurchaseStockProduct
                        $purchaseStock = PurchaseStockProduct::where('stock_product_id', $stockEntry->id)->first();
                        if ($purchaseStock) {
                            $purchaseStock->update($entry);
                        } else {
                            $entry['stock_product_id'] = $stockEntry->id;
                            $purchaseStock = PurchaseStockProduct::create($entry);
                        }
                    } else {
                        // Create new stock entry
                        $stockEntry = StockEntry::create($entry);
                        $entry['stock_product_id'] = $stockEntry->id;
                        $purchaseStock = PurchaseStockProduct::create($entry);
                    }

                    // Save field values
                    if (!empty($entry['field_values']) && is_array($entry['field_values'])) {
                        foreach ($entry['field_values'] as $fieldGroup) {
                            foreach ($fieldGroup as $fieldValue) {
                                StockProductFieldValue::create([
                                    'stock_product_id' => $stockEntry->id,
                                    'company_id' => $entry['company_id'],
                                    'product_id' => $stockEntry->product_id,
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'quantity_index' => $fieldValue['quantity_index'],
                                ]);

                                PurchaseStockProductFieldValue::create([
                                    'stock_product_id' => $stockEntry->id,
                                    'purchase_product_id' => $purchaseStock->id,
                                    'company_id' => $entry['company_id'],
                                    'product_id' => $stockEntry->product_id,
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'value' => $fieldValue['value'],
                                    'quantity_index' => $fieldValue['quantity_index'],
                                ]);
                            }
                        }
                    }

                    $updatedEntries[] = $stockEntry->load('fieldValues');
                }
            });

            return response()->json([
                'message' => 'Stock entries processed successfully',
                'data' => $updatedEntries,
            ], 200);

        } catch (QueryException $e) {
            \Log::error('Database error in StockEntry update', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in StockEntry update', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }


    public function show($id): JsonResponse
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
