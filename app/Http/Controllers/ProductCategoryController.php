<?php

namespace App\Http\Controllers;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

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


    public function categoryList(Request $request): JsonResponse
    {
        try {

            $companyId = $request->company_id;

            if (!$companyId) {
                return response()->json(["error" => "No Associated company Found !!"], 404);
            }

            $categories = ProductCategory::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('name');

            return response()->json([
                "message" => "Category List Received",
                "data" => $categories
            ], 200);



        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Category Not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error ocurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }

    }

    public function categoryDetails(Request $request):JsonResponse{
        try{
            $companyId = $request->company_id;
            $categoryName = $request->category_name;

            if(!$companyId){
                return response()->json(["error"=>"Company not Found"],404);
            }

            $category = ProductCategory::where('company_id',$companyId)
                                        ->where('name',$categoryName)
                                         ->whereNull('deleted_at')
                                         ->firstorFail();

             return response()->json(["message"=>"Category Details Received",
                                        "data"=>$category
                                    ],200);                            

        }catch(ModelNotFoundException $e){
            return response()->json(["error"=>"Catgory Not Found !!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred"],500);
        }

    }








    // Store a new resource
    public function store(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'company_id' => 'required|integer|exists:companies,id',
            'is_primary' => 'boolean',
            'is_active' => 'boolean'
        ]);
        // If is_primary is true, set all other categories for this company to not primary
        if (!empty($validated['is_primary'])) {
            ProductCategory::where('company_id', $validated['company_id'])
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        // Set default values if not provided
        $validated['is_primary'] = $validated['is_primary'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;


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


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $product_category = ProductCategory::findOrFail($id);
            $validated = $request->validate([
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('product_categories')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $product_category) {
                            return $query->where('company_id', $request->input('company_id', $product_category->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'company_id' => 'sometimes|required|integer|exists:companies,id',
                'is_active' => 'sometimes|boolean',
                'is_primary' => 'sometimes|boolean',
            ]);

            // If is_primary is being set to true, ensure no other category remains primary
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                ProductCategory::where('company_id', $product_category->company_id)
                    ->where('id', '!=', $id) // Exclude the current category
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            // Explicit boolean handling (optional, since validation ensures boolean)
            if ($request->has('is_active')) {
                $validated['is_active'] = (bool) $request->input('is_active');
            }
            if ($request->has('is_primary')) {
                $validated['is_primary'] = (bool) $request->input('is_primary');
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
