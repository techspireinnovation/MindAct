<?php

namespace App\Http\Controllers;
use App\Models\ProductSubCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Http\Request;

class ProductSubCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductSubCategory::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    

        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:product_sub_categories,name' . $id,
                'is_active' => 'boolean|required',
                'category_id' => 'integer|exists:product_categories,id',
                'company_id' => 'integer|exists:companies,id'
            ]);
            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:product_sub_categories,name',
            'is_active' => 'boolean|required',
            'category_id' => 'integer|exists:product_categories,id',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = ProductSubCategory::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Product Sub Category deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}
