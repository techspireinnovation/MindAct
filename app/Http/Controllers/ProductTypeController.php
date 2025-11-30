<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductType::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

    public function productTypeList(Request $request)
    {
     

        try {

            $types = ProductType::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->get(['id', 'name'])
                ->map(fn($type) => ['id' => $type->id, 'name' => $type->name])
                ->values()
                ->toArray();
            return response()->json([
                "message" => "Product Type List Received !!",
                "data" => $types
            ]);

        } catch (ModelNotFoundException $e) {
           
            return response()->json(["error" => "Product Type not Found !!"], 404);
        } catch (QueryException $e) {
           
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
           
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function productTypeDetails(Request $request)
    {
        try {

            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $type = $request->type_name;
            
               
            $typeDetails = ProductType::where('company_id', $companyId)
                ->where('name', $type)
                ->whereNull('deleted_at')
                ->firstorFail();
             
            return response()->json([
                "message" => "Product Type Details Received !!",
                "data" => $typeDetails
            ], 200);


        } catch (ModelNotFoundException $e) {
           
            return response()->json(["error" => "Not Item Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }




    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = ProductType::findOrFail($id);
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('product_types')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                
                'company_id' => 'integer'
            ]);
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                ProductType::where('company_id', $item->company_id)
                    ->where('id', '!=', $id)
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

            $item->update($validated);
            $item->refresh();

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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_types')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'is_active' => 'boolean|required',
            'is_primary' => 'boolean',
            'company_id' => 'integer'
        ]);
        if (!empty($validated['is_primary'])) {
            ProductType::where('company_id', $validated['company_id'])
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $validated['is_primary'] = $validated['is_primary'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;


        $item = ProductType::create($validated);
        return response()->json($item, 201);
    }

    public function getById($id): JsonResponse
    {
        try {
            $item = ProductType::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
          
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
          
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }


     public function show($id): JsonResponse
    {
        try {
            $item = ProductType::findOrFail($id);
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
            $type = ProductType::findOrFail($id);

            // Check usage
            if ($type->products()->exists()) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Product Type cannot be deleted because it is assigned to one or more products.'
                ], 400);
            }

            $type->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product Type deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
           
            return response()->json([
                'error' => 'not_found',
                'message' => 'Product Type not found!'
            ], 404);

        } catch (QueryException $e) {
           
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the product type.'
            ], 500);

        } catch (\Exception $e) {
           
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the product type.'
            ], 500);
        }
    }


    public function activeProductTypeList(Request $request): JsonResponse
    {
        try {
            $types = ProductType::where('company_id', $request->company_id)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'is_primary']) // ✅ fetch is_primary too
                ->map(fn($type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'is_primary' => $type->is_primary, // ✅ include in response
                ])
                ->values()
                ->toArray();

            if (empty($types)) {
                return response()->json([
                    "message" => "No active product types found !!",
                    "data" => []
                ], 200);
            }

            return response()->json([
                "message" => "Active product types received !!",
                "data" => $types
            ], 200);

        } catch (QueryException $e) {
           
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
           
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}
