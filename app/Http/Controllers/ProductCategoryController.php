<?php

namespace App\Http\Controllers;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{

    // Display a listing
    public function index(): JsonResponse
    {
        $product = ProductCategory::all();

        return response()->json(ProductCategory::paginate(10));
    }

    // Store a new resource
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_id' => 'required|integer|exists:companies,id',
            'is_active' => ''
        ]);
        $post = ProductCategory::create($validated);
        return response()->json($post, 201);
    }

    // Show a single resource
    public function show(ProductCategory $productCategory): JsonResponse
    {
        return response()->json($productCategory);
    }

    // Update a resource
    public function update(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'company_id' => 'sometimes|required|integer|exists:companies,id',
            'is_active' => 'sometimes|nullable|boolean',
        ]);
        $productCategory->update($validated);
        return response()->json($productCategory);
    }

    // Delete a resource
    public function destroy(ProductCategory $productCategory): JsonResponse
    {
        $productCategory->delete();
        return response()->json(['message' => 'Product Category deleted']);
    }
}
