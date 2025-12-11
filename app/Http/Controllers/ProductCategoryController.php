<?php

namespace App\Http\Controllers;
use App\Http\Requests\ProductCategoryRequest\StoreRequest;
use App\Http\Requests\ProductCategoryRequest\UpdateRequest;
use App\Interfaces\ProductCategoryRepositoryInterface;

use App\Http\Resources\ProductCategoryCollection;
use App\Http\Resources\ProductCategoryResource;

use App\Models\ProductCategory;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Validator;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductCategoryController extends Controller
{

    protected $repository;

    public function __construct(ProductCategoryRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->only('keywords');

            $categories = $this->repository->list($filters);

            return new ProductCategoryCollection($categories);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function categoryDetails(Request $request)
    {
        try {


            $categoryName = $request->category_name;

            $categoryDetail = $this->repository->categoryDetails($categoryName);


            return new ProductCategoryResource($categoryDetail);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Catgory Not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred"], 500);
        }

    }





    
    public function store(StoreRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();


            $category = $this->repository->create($validated);

            return response()->json($category, 201);


        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Company not found'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    
    public function show($id)
    {
        try {
            $category = $this->repository->show($id);
            return new ProductCategoryResource($category);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Category not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }


    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            $validated = $request->validated();



            $category = $this->repository->update($id, $validated);

            return response()->json($category, 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Category not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Update failed'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Product Category deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Product Category not found!'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Product Category.'
            ], 500);

        } catch (\Exception $e) {

            if (str_starts_with($e->getMessage(), 'in_use:')) {
                $usedIn = explode(',', str_replace('in_use:', '', $e->getMessage()));
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Product Category cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);

            }

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Product Category.'
            ], 500);


        }
    }


    public function activeCategoryList(Request $request)
    {
        try {
            $categories = $this->repository->activeCategoryList();

            return ProductCategoryResource::collection($categories)
                ->map(fn($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Category Not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}
