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
use Validator;

class JournalVoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Step 1: Eager load relationships
        $query = JournalVoucher::with(['transactions', 'salesman', 'project']);

        // Step 2: Apply keyword filter if needed
        if ($request->has('keywords')) {
            $query->where('voucher_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('reference_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        // Step 3: Paginate
        $data = $query->paginate(50);

        // Step 4: Transform each item to include names instead of nested objects
        $data->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'company' => $item->company_id,
                'voucher_number' => $item->voucher_number,
                'reference_number' => $item->reference_number,
                'date' => $item->date,
                'salesman_id' => $item->salesman_id,
                'salesman_name' => optional($item->salesman)->name,
                'project_id' => $item->project_id,
                'project_name' => optional($item->project)->name,
                'transactions' => $item->transactions, // already loaded
            ];
        });
        // Step 5: Return transformed paginated response
        return response()->json($data);
    }


    public function print(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_date' => 'required',
            'to_date' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $query = JournalVoucherTransaction::select('journal_voucher_transactions.id', 'particulars', 'debit', 'credit', DB::raw('SUM((debit) - COALESCE(credit, 0)) OVER (ORDER BY id) as balance'), 'projects.name', 'reference_number', 'journal_vouchers.date')->leftJoin("journal_vouchers", 'journal_vouchers.id', '=', 'journal_voucher_transactions.journal_voucher_id')->leftJoin("projects", 'projects.id', '=', 'journal_vouchers.project_id')->when(isset($request->from_date) && isset($request->to_date), function ($query1) use ($request) {
            $query1->where('date', '>=', $request->from_date)->where('date', '<=', $request->to_date);
        })->get();


        return response()->json($query);
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
                    'transactions.mainGroup:id,name',
                    'transactions.accountGroup:id,name',
                    'transactions.accountHead:id,name',
                    'transactions.subGroup:id,name',
                    'project:id,name',
                    'salesman:id,name',

                ])
                ->findOrFail($id);
            return response()->json([
                'item' => $product
            ]);
        } catch (ModelNotFoundException $e) {
            Log::error($e);
            return response()->json(['error' => 'Journal Voucher not found!'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            Log::error($e);
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
