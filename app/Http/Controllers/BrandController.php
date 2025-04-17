<?php

namespace App\Http\Controllers;
use App\Models\Brand;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Brand::paginate(10));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Brand::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean|required',
                'quantity' => 'integer',
                'symbol' => 'string|max:255',
                'company_id' => 'integer|exists:companies,id'
            ]);
            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean|required',
            'quantity' => 'integer',
            'symbol' => 'string|max:255',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Brand::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Brand::findOrFail($id);
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
            $item = Brand::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Brand deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
