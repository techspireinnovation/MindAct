<?php

namespace App\Http\Controllers;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductCategoryController extends Controller
{

    // Display a listing

    public function index(Request $request): JsonResponse
{
    $query = ProductCategory::query();

    // Check for 'keywords' and apply the filter if present
    if ($request->has('keywords')) {
        $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
    }

    // Check for 'company_id' and apply the filter if present
    if ($request->has('company_id')) {
        $query->where('company_id', $request->input('company_id'));
    }

    // Paginate the result, you can set the pagination size as needed
    $categories = $query->paginate(50);

    return response()->json($categories);
}


    // Store a new resource
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:product_categories,name',
            'company_id' => 'required|integer|exists:companies,id',
            'is_active' => ''
        ]);
        $post = ProductCategory::create($validated);
        return response()->json($post, 201);
    }

    // Show a single resource
    public function show($id): JsonResponse
    {
        try {
            $category = ProductCategory::findOrFail($id);
            return response()->json($category);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Category not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    // Update a resource
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $product_category = ProductCategory::findOrFail($id);
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255|unique:product_categories,name,' . $id,
                'company_id' => 'sometimes|required|integer|exists:companies,id',
                'is_active' => 'sometimes|boolean',
            ]);

            // Explicit boolean handling
            if ($request->has('is_active')) {
                $validated['is_active'] = (bool) $request->input('is_active');
            }

            $product_category->update($validated);
            $product_category->refresh(); // Refresh to get updated values

            return response()->json($product_category);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Category not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Update failed'], 500);
        }
    }

    // Delete a resource
    public function destroy($id): JsonResponse
    {
        try {

            $product_category = ProductCategory::findorFail($id);

            $product_category->delete();

            return response()->json(['message' => 'Product Category deleted!!']);

        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Product Category not found'], 404);
        } catch (QueryException) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        }
    }
}
