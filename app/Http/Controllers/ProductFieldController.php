<?php

namespace App\Http\Controllers;

use App\Models\ProductField;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class ProductFieldController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductField::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }
    public function productFieldList(Request $request)
    {
        try {

            $productFields = ProductField::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->get(['id', 'name'])
                ->map(fn($productField) => ['id' => $productField->id, 'name' => $productField->name])
                ->values()
                ->toArray();
            return response()->json([
                "message" => "Product Field List Received !!",
                "data" => $productFields
            ]);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Product Field not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }
    public function productFieldDetails(Request $request)
    {
        try {

            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $productField = $request->product_field_name;
            $productFieldDetails = ProductField::where('company_id', $request->company_id)
                ->where('name', $productField)
                ->whereNull('deleted_at')
                ->firstorFail();
            return response()->json([
                "message" => "Product Field Details Received !!",
                "data" => $productFieldDetails
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Product Field not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_fields')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'is_active' => 'boolean|required',
            'company_id' => 'integer',
            'type' => 'required|string|in:text,dropdown',
            'values' => 'required_if:type,dropdown|array',
            'values.*' => 'required_if:type,dropdown|string|max:255',
        ]);

        $product_field = ProductField::create($validated);
        return response()->json($product_field, 201);
    }



    public function show($id): JsonResponse
    {
        try {
            $product_field = ProductField::findOrFail($id);
            return response()->json($product_field);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $product_field = ProductField::findOrFail($id);
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('product_fields')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $product_field) {
                            return $query->where('company_id', $request->input('company_id', $product_field->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'is_active' => 'boolean|required',
                'company_id' => 'integer',
                'type' => 'required|string|in:text,dropdown',
                'values' => 'required_if:type,dropdown|array',
                'values.*' => 'required_if:type,dropdown|string|max:255',

            ]);
            $product_field->update($validated);
            return response()->json($product_field);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $field = ProductField::findOrFail($id);

            $usedIn = [];

            if ($field->productFieldValues()->exists()) {
                $usedIn[] = 'product_field_values';
            }

            if ($field->purchaseProductFieldValues()->exists()) {
                $usedIn[] = 'purchase_product_field_values';
            }

            if ($field->salesProductFieldValues()->exists()) {
                $usedIn[] = 'sales_product_field_values';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Product Field cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);
            }

            $field->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product Field deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'not_found',
                'message' => 'Product Field not found!'
            ], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the product field.'
            ], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the product field.'
            ], 500);
        }
    }
    public function activeProductFields(Request $request): JsonResponse
    {
        try {
            $activeFields = ProductField::where('is_active', 1)
                ->where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->select('id', 'name', 'type', 'is_active')
                ->paginate(50);

            return response()->json([
                'message' => 'Active Product Fields Retrieved Successfully!',
                'data' => $activeFields
            ], 200);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'Database error occurred!!'
            ], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'An unexpected error occurred!!'
            ], 500);
        }
    }
}
