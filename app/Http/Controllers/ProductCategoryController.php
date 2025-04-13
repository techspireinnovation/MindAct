<?php

namespace App\Http\Controllers;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
        try{
        $product_category = ProductCategory::findOrFail($id);

        $validated = $request->validate([

            'name' => 'sometimes|required|string|max:255',
            'company_id' => 'sometimes|required|integer|exists:companies,id',
            'is_active' => 'sometimes|nullable|boolean',
        ]);

        $product_category->update($validated);

        return response()->json($product_category);

       }catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Product Category not found!!'], 404);
        }catch (QueryException $e) {
             return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    // Delete a resource
    public function destroy($id): JsonResponse
    {
        try{

            $product_category = ProductCategory::findorFail($id);

            $product_category->delete();

           return response()->json(['message' => 'Product Category deleted!!']);

        }catch(ModelNotFoundException){
            return response()->json(['error' => 'Product Category not found'], 404);
        }catch(QueryException){
            return response()->json(['error' => 'An unexpected error occurred!!'],500);

        }
    }
}
