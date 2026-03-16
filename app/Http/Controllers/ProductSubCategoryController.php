<?php

namespace App\Http\Controllers;
use App\Models\ProductSubCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductSubCategoryController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = ProductSubCategory::with('category:id,name');

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }


        $subCategories = $query->paginate(50);


        $transformed = $subCategories->getCollection()->map(function ($subCategory) {
            return [
                'id' => $subCategory->id,
                'name' => $subCategory->name,
                'category_id' => optional($subCategory->category)->id,
                'category_name' => optional($subCategory->category)->name,
                'is_active' => $subCategory->is_active,
                'company_id' => $subCategory->company_id,

            ];
        });


        $subCategories->setCollection($transformed);

        return response()->json($subCategories);
    }



    public function subCategoryList(Request $request)
    {
        try {

            $subCategories = ProductSubCategory::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->get(['id', 'name'])
                ->map(fn($subCategory) => ['id' => $subCategory->id, 'name' => $subCategory->name])
                ->values()
                ->toArray();
            return response()->json([
                "message" => "Sub Category List Received !!",
                "data" => $subCategories
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Sub Category not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function subCategoryDetails(Request $request)
    {
        try {

            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $category = $request->category_name;
            $categoryDetails = ProductSubCategory::where('company_id', $request->company_id)
                ->where('name', $category)
                ->whereNull('deleted_at')
                ->firstorFail();
            return response()->json([
                "message" => "Sub Category Details Received !!",
                "data" => $categoryDetails
            ], 200);


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Sub Category Found ."], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('product_sub_categories')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->where('category_id', $request->input('category_id', $item->category_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'is_active' => 'boolean|required',
                'category_id' => [
                    'required',
                    'integer',
                    Rule::exists('product_categories', 'id')->whereNull('deleted_at')
                ],
                'company_id' => 'integer'
            ]);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $validated = $validator->validated();

            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed !!',
                'errors' => $e->errors()
            ], 422);

        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An unexpected error occurred !'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {

        try {

            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('product_sub_categories')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->where('category_id', $request->category_id)
                            ->whereNull('deleted_at');

                    }),
                ],
                'is_active' => 'boolean|required',
                'category_id' => [
                    'required',
                    'integer',
                    Rule::exists('product_categories', 'id')->whereNull('deleted_at')
                ],

            ]);

            $validated['company_id'] = $request->company_id;

            $item = ProductSubCategory::create($validated);
            return response()->json([
                'message' => 'Product Sub Category created !!',
                'data' => $item
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Item not found !!'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed !!',
                'errors' => $e->errors()
            ], 422);

        } catch (QueryException $e) {
            \Log::error('QueryException: ' . $e->getMessage());
            return response()->json(['message' => 'Database error occurred !!'], 500);

        } catch (Exception) {
            return response()->json(['message' => 'An unexpected error occurred !!']);

        }
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
        } catch (\Exception $e) {
            return response()->json(['message' => 'An unexpected error occurred !!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $unit = ProductSubCategory::findOrFail($id);

            $usedIn = [];

            if ($unit->productscategory()->exists()) {
                $usedIn[] = 'products';
            }


            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Product category cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);
            }

            $unit->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product category deleted successfully!!'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Product category not found!'
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Product category !'
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Product category.'
            ], 500);
        }
    }


    public function activeSubCategoryList(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id;

            if (!$companyId) {
                return response()->json(["error" => "No Associated company Found !!"], 404);
            }

            $subCategories = ProductSubCategory::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->get(['id', 'name', 'category_id'])
                ->map(fn($subCategory) => [
                    'id' => $subCategory->id,
                    'name' => $subCategory->name,
                    'category_id' => $subCategory->category_id
                ])
                ->values()
                ->toArray();

            return response()->json([
                "message" => "Active Sub Category List Received !!",
                "data" => $subCategories
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Sub Category not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }



}
