<?php

namespace App\Http\Controllers;
use App\Models\AccountGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountGroupController extends Controller
{


    public function index(Request $request): JsonResponse
    {
        $query = AccountGroup::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'main_group_id' => 'integer|exists:main_groups,id',
                'sub_group_id' => 'integer|exists:sub_groups,id',
                'code' => 'string|max:255',

            ]);
            $group->update($validated);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Group not found!!'], 404);
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
            'main_group_id' => 'integer|exists:main_groups,id',
            'sub_group_id' => 'integer|exists:sub_groups,id',
            'code' => 'string|max:255'
        ]);

        $group = AccountGroup::create($validated);
        return response()->json($group, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($id);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($id);
            $group->delete();
            return response()->json(['message' => 'Account Group deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}
