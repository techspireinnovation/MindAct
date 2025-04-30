<?php

namespace App\Http\Controllers;

use App\Models\MainGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class MainGroupController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = MainGroup::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $group = MainGroup::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:|unique:main_groups,name,' . $id,
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id'
            ]);
            $group->update($validated);
            return response()->json($group, 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:main_groups,name',
            'is_active' => 'boolean|required',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $group = MainGroup::create($validated);
        return response()->json($group, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $group = MainGroup::findOrFail($id);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $group = MainGroup::findOrFail($id);
            $group->delete();
            return response()->json(['message' => 'Main Group deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}
