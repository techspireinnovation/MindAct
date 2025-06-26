<?php

namespace App\Http\Controllers;

use App\Models\JournalVoucher;
use App\Models\JournalVoucherTransaction;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Log;

class JournalVoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JournalVoucher::query()->with('transactions');

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'voucher_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('journal_vouchers')->where(function ($query) use ($request, $id) {
                        return $query->where('company_id', $request->company_id)->where('id', '!=', $id)->
                            whereNull('deleted_at');

                    }),
                ],
                'reference_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('journal_vouchers')->where(function ($query) use ($request, $id) {
                        return $query->where('company_id', $request->company_id)->where('id', '!=', $id)
                            ->whereNull('deleted_at');

                    }),
                ],
                'project_id' => 'integer|exists:projects,id',
                'salesman_id' => 'integer|exists:salesmen,id',
                'date' => 'nullable|string',
                'transactions' => 'nullable|array',
                'transactions.*.main_group_id' => 'nullable|integer|exists:main_groups,id',
                'transactions.*.account_group_id' => 'nullable|integer|exists:account_groups,id',
                'transactions.*.account_head_id' => 'nullable|integer|exists:account_heads,id',
                'transactions.*.sub_group_id' => 'nullable|integer|exists:sub_groups,id',
                'transactions.*.account_code' => 'nullable|string',
                'transactions.*.particulars' => 'nullable|string|max:255',
                'transactions.*.type' => 'nullable|string|max:255',
                'transactions.*.debit' => 'nullable|numeric',
                'transactions.*.credit' => 'nullable|numeric',
                'company_id' => 'integer|exists:companies,id'
            ]);

            DB::transaction(function () use ($validated, $id, &$product) {
                $product = JournalVoucher::findOrFail($id);
                $product->update($validated);


                // Handle field values
                $existingFieldValueIds = $product->transactions()->pluck('id')->toArray();
                $incomingFieldValueIds = collect($validated['transactions'] ?? [])->pluck('id')->filter()->toArray();

                foreach ($validated['transactions'] as $childData) {
                    if (isset($childData['id'])) {
                        // Update existing child
                        $child = JournalVoucherTransaction::find($childData['id']);
                        $child->update($childData);
                    } else {
                        // Create new child
                        $product->transactions()->create($childData);
                    }
                }
                $fieldsValuesToDelete = array_diff($existingFieldValueIds, $incomingFieldValueIds);
                JournalVoucherTransaction::whereIn('id', $fieldsValuesToDelete)->delete();

            });

            return response()->json(['message' => 'Journal Voucher Updated']);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voucher_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('journal_vouchers')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');

                }),
            ],
            'reference_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('journal_vouchers')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');

                }),
            ],
            'project_id' => 'integer|exists:projects,id',
            'salesman_id' => 'integer|exists:salesmen,id',
            'date' => 'nullable|string',
            'transactions' => 'nullable|array',
            'transactions.*.main_group_id' => 'nullable|integer|exists:main_groups,id',
            'transactions.*.account_group_id' => 'nullable|integer|exists:account_groups,id',
            'transactions.*.account_head_id' => 'nullable|integer|exists:account_heads,id',
            'transactions.*.sub_group_id' => 'nullable|integer|exists:sub_groups,id',
            'transactions.*.account_code' => 'nullable|string',
            'transactions.*.particulars' => 'nullable|string|max:255',
            'transactions.*.type' => 'nullable|string|max:255',
            'transactions.*.debit' => 'nullable|numeric',
            'transactions.*.credit' => 'nullable|numeric',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = JournalVoucher::create($validated);

        if (isset($validated['transactions'])) {
            $item->transactions()->createMany($validated['transactions']);
        }

        return response()->json([
            'item' => $item,
            'action' => 'created',
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        try {
            $product = JournalVoucher::where('company_id', $request->company_id)
                ->with([
                    'transactions',
                ])
                ->findOrFail($id);


            return response()->json([
                'item' => $product
            ]);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Journal Voucher not found!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = JournalVoucher::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Journal Voucher deleted!!']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
