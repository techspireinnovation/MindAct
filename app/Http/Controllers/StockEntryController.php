<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Auth;
use DB;
use Illuminate\Support\Facades\Log;
use App\Models\PurchaseStockProduct;
use App\Models\StockEntry;
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
        $query = StockEntry::query();

        $stockEntries = $query->paginate(50);
        $transformed = $stockEntries->getCollection()->map(function ($stockEntry) {
            return [
                'id' => $stockEntry->id,
                'name' => $stockEntry->name,
                'product_id' => $stockEntry->product_id,
                'branch_id' => $stockEntry->branch_id,
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
                // 'stock_entries.*.field_values.*.*.quantity_index' => '|numeric|min:1',
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

                // $entry['branch_id'] = $request->branch_id;

                // Create stock entry
                $stockEntry = StockEntry::create($entry);

                $entry['stock_product_id'] = $stockEntry->id;

                $purchaseStock = PurchaseStockProduct::create($entry);

                // If there are field values, save them
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

            \Log::error('Unexpected error in StockEntry store', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }


    public function update(Request $request): JsonResponse
    {
        try {
            // Validation rules
            $validator = Validator::make($request->all(), [
                'branch_id' => 'nullable|integer|exists:branches,id',
                'stock_entries' => 'required|array',
                'stock_entries.*.id' => 'nullable|exists:stock_entries,id',
                'stock_entries.*.product_code' => 'required|string|max:255',
                'stock_entries.*.product_name' => 'nullable|string|max:255',
                'stock_entries.*.product_id' => 'nullable|numeric|exists:products,id',
                'stock_entries.*.branch_id' => 'required|numeric|exists:branches,id',
                'stock_entries.*.purchase_type' => 'required|string',
                'stock_entries.*.uom' => 'required|numeric|exists:measure_units,id',
                'stock_entries.*.batch_no' => 'nullable|string|max:255',
                'stock_entries.*.expiry_date' => 'nullable|string|max:255',
                'stock_entries.*.quantity' => 'nullable|numeric',
                'stock_entries.*.rate' => 'nullable|numeric',
                'stock_entries.*.amount' => 'nullable|numeric',
                'stock_entries.*.location_id' => 'nullable|exists:locations,id',
                'stock_entries.*.customer_id' => 'nullable|numeric|exists:customers,id',
                'stock_entries.*.measure_unit_id' => 'nullable|numeric|exists:measure_units,id',
                'stock_entries.*.field_values' => 'nullable|array',
                'stock_entries.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'stock_entries.*.field_values.*.*.value' => 'required|string|max:255',

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed. Please check the provided data.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Use the authenticated user's branch_id
            $userBranchId = $request->branch_id;
            $companyId = $request->company_id; // Set by middleware
            $targetBranchId = $request->branch_id; // Set by middleware or request

            // Check if the user's branch is main (branch_type = 'Main', 'main', or 'MAIN')
            $userBranchTypeData = Branch::where('id', $userBranchId)->firstOrFail();

            $userBranchType = strtolower($userBranchTypeData->branch_type ?? '');
            $isMainBranch = in_array($userBranchType, ['main']);

            Log::info('StockEntry update: Starting process', [
                'user_id' => $user->id,
                'user_branch_id' => $userBranchId,
                'user_branch_type' => $userBranchType,
                'company_id' => $companyId,
                'is_main_branch' => $isMainBranch,
                'target_branch_id' => $targetBranchId,
                'request_data' => $request->all(),
            ]);

            // Check if target branch is main (for deletion scoping)
            $isTargetMainBranch = false;
            if ($targetBranchId !== null) {
                $targetBranchTypeData = Branch::where('id', $targetBranchId)->firstOrFail();
                $targetBranchType = strtolower($targetBranchTypeData->branch_type ?? '');
                $isTargetMainBranch = in_array($targetBranchType, ['main']);
            }

            // Authorization: Non-main branch users can only update their own branch unless target is main branch
            if ($targetBranchId !== null && !$isTargetMainBranch && !$isMainBranch && $targetBranchId != $userBranchId) {
                throw new \Exception("You are not authorized to update for branch ID {$targetBranchId}.");
            }

            $updatedEntries = [];

            DB::transaction(function () use ($request, $companyId, $userBranchId, $isMainBranch, $targetBranchId, $isTargetMainBranch, &$updatedEntries) {
                $providedIds = [];
                $newEntryIds = []; // Track IDs of newly created entries

                foreach ($request->stock_entries as $entry) {
                    // Restrict non-main branch users to their own branch unless target is main branch
                    if ($targetBranchId !== null && !$isTargetMainBranch && !$isMainBranch && $entry['branch_id'] != $targetBranchId) {
                        throw new \Exception("Provided stock entry branch_id {$entry['branch_id']} does not match the target branch_id {$targetBranchId}.");
                    }

                    // If no targetBranchId and not main branch, restrict to user's branch
                    if ($targetBranchId === null && !$isMainBranch && $entry['branch_id'] != $userBranchId) {
                        throw new \Exception("You are not authorized to update stock entries for branch ID {$entry['branch_id']}.");
                    }

                    Log::info('StockEntry update: Processing entry', [
                        'entry_id' => $entry['id'] ?? 'new',
                        'request_branch_id' => $entry['branch_id'],
                        'user_branch_id' => $userBranchId,
                    ]);

                    // Resolve product_id from product_code if not provided
                    if (empty($entry['product_id']) && !empty($entry['product_code'])) {
                        $product = Product::where('product_code', $entry['product_code'])
                            ->where('company_id', $companyId)
                            ->first();
                        if (!$product) {
                            throw new \Exception("Invalid product code `{$entry['product_code']}` or product does not belong to company ID {$companyId}.");
                        }
                        $entry['product_id'] = $product->id;
                    }
                    $entry['company_id'] = $companyId;

                    // Update or create stock entry
                    if (!empty($entry['id'])) {
                        // Update existing stock entry
                        $stockEntry = StockEntry::where('id', $entry['id'])
                            ->where('company_id', $companyId)
                            ->firstOrFail();

                        Log::info('StockEntry update: Authorization check', [
                            'stock_entry_id' => $stockEntry->id,
                            'stock_entry_branch_id' => $stockEntry->branch_id,
                            'user_branch_id' => $userBranchId,
                            'is_main_branch' => $isMainBranch,
                        ]);

                        // Allow main branch to update any branch, otherwise restrict to user's branch
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

                        $providedIds[] = $entry['id'];
                    } else {
                        // Allow main branch to create for any branch, otherwise restrict to user's branch
                        if (!$isMainBranch && $entry['branch_id'] != $userBranchId) {
                            throw new \Exception("You are not authorized to create stock entries for branch ID {$entry['branch_id']}.");
                        }

                        // Create new stock entry
                        $stockEntry = StockEntry::create($entry);
                        $newEntryIds[] = $stockEntry->id; // Track new entry ID
                        $entry['stock_product_id'] = $stockEntry->id;
                        $purchaseStock = PurchaseStockProduct::create($entry);
                    }

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

                    $updatedEntries[] = $stockEntry->load('fieldValues');
                }

                // Delete stock entries not in the payload, scoped by permission
                $scopeQuery = StockEntry::where('company_id', $companyId);
                if ($targetBranchId !== null && !($isTargetMainBranch && $isMainBranch)) {
                    // Scope deletion to the target branch, unless both target and user are main branch
                    $scopeQuery->where('branch_id', $targetBranchId);
                } elseif (!$isMainBranch) {
                    // Non-main branch users can only delete from their own branch
                    $scopeQuery->where('branch_id', $userBranchId);
                }
                // If both targetBranchId and user are main branch, delete across all branches for the company
                $existingIds = $scopeQuery->pluck('id')->toArray();
                $idsToDelete = array_diff($existingIds, array_merge($providedIds, $newEntryIds));

                if (!empty($idsToDelete)) {
                    Log::info('StockEntry update: Deleting entries', ['ids_to_delete' => $idsToDelete]);
                    StockProductFieldValue::whereIn('stock_product_id', $idsToDelete)->delete();
                    PurchaseStockProductFieldValue::whereIn('stock_product_id', $idsToDelete)->delete();
                    PurchaseStockProduct::whereIn('stock_product_id', $idsToDelete)->delete();
                    StockEntry::whereIn('id', $idsToDelete)->delete();
                }
            });

            return response()->json([
                'message' => 'Stock entries processed successfully',
                'data' => $updatedEntries,
            ], 200);

        } catch (QueryException $e) {
            dd($e->getMessage());
            Log::error('Database error in StockEntry update', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);
            return response()->json(['message' => 'Database error occurred. Please try again.'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in StockEntry update', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);
            return response()->json(['message' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }
    public function show(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            $branchData = Branch::where('id', $branchId)->firstOrFail();

            // Check if the branch is main
            $isMainBranch = strtolower($branchData->branch_type ?? '') === 'main';

            if ($isMainBranch) {
                $item = StockEntry::where('company_id', $companyId)->with('fieldValues')->get();
            } else {
                $item = StockEntry::where('company_id', $companyId)
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
