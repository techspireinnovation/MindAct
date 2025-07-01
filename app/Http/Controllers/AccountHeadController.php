<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\VoucherSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AccountHeadController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = AccountHead::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('account_heads')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $account_head) {
                            return $query->where('company_id', $request->input('company_id', $account_head->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer|exists:companies,id',
                'account_group_id' => 'integer|exists:account_groups,id',
                'code' => 'string|max:255',

            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();

            if ($this->checkIfUsed($id))
                return response()->json(['error' => 'Cannot not modify. The item has already been used'], 406);

            $account_head->update($validated);

            return response()->json($account_head);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('account_heads')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');

                    }),

                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer|exists:companies,id',
                'account_group_id' => 'integer|exists:account_groups,id',
                'code' => 'string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();
            $account_head = AccountHead::create($validated);
            return response()->json($account_head, 201);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Account Head  not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            return response()->json($account_head);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            if ($this->checkIfUsed($id))
                return response()->json(['error' => 'Cannot not modify. The item has already been used'], 406);

            $account_head = AccountHead::findOrFail($id);
            $account_head->delete();
            return response()->json(['message' => 'Account Head deleted!!']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    private function checkIfUsed($id): bool
    {
        if (VoucherSummary::where('account_head_id', $id)->first()) {
            return true;
        }
        return false;

    }
}
