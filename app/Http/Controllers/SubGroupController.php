<?php

namespace App\Http\Controllers;

use App\Models\SubGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubGroupController extends Controller
{

    public function index(Request $request): JsonResponse
    {
         $query = SubGroup::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:sub_groups,name,' . $id,
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'main_group_id' => 'integer|exists:main_groups,id',
                'code' => 'string|max:255',
                'ranking_for_trial' => 'integer|max:255'
            ]);
            $group->update($validated);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:sub_groups,name',
            'is_active' => 'boolean|required',
            'company_id' => 'integer|exists:companies,id',
            'main_group_id' => 'integer|exists:main_groups,id',
            'code' => 'string|max:255',
            'ranking_for_trial' => 'string|max:255'
        ]);

        $group = SubGroup::create($validated);
        return response()->json($group, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            $group->delete();
            return response()->json(['message' => 'Sub Group deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}
