<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use App\Models\AccountHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:account_heads,' . $id,
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'account_group_id' => 'integer|exists:account_groups,id',
                'code' => 'string|max:255',

            ]);
            $account_head->update($validated);
            return response()->json($account_head);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean|required',
            'company_id' => 'integer|exists:companies,id',
            'account_group_id' => 'integer|exists:account_groups,id',
            'code' => 'string|max:255'
        ]);

        $account_head = AccountHead::create($validated);
        return response()->json($account_head, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            return response()->json($account_head);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            $account_head->delete();
            return response()->json(['message' => 'Account Head deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}
