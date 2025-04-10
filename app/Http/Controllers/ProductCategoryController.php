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
        return response()->json(ProductCategory::paginate(10));
    }

    // Store a new resource
    public function store(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_id' => 'required',
            'is_active'=>''
        ]);

        $post = ProductCategory::create($validated);

        return response()->json($post, 201);
    }

    // Show a single resource
    public function show(Company $post): JsonResponse
    {
        return response()->json($post);
    }

    // Update a resource
    public function update(Request $request, Company $post): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string',
        ]);

        $post->update($validated);

        return response()->json($post);
    }

    // Delete a resource
    public function destroy(Company $post): JsonResponse
    {
        $post->delete();

        return response()->json(['message' => 'Company deleted']);
    }
}
