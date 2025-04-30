<?php

namespace App\Http\Controllers;


use App\Events\ProductUpdated;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductList;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    


//     public function index(Request $request): JsonResponse
// {
//     try {
//         $query = Product::query()->with([
//             'category',
//             'subCategory',
//             'brand',
//             'measureUnit',
//             'productType',
//             'location',
          
          
//         ]);

//         $search = $request->input('search');
//         $filterBy = $request->input('filter_by', 'all'); 

//         if ($search) {
//             $filterOptions = explode(',', $filterBy); // Split comma-separated filter_by values

//             $query->where(function ($q) use ($search, $filterOptions) {
               
//                 if (in_array('all', $filterOptions)) {
//                     $q->where('name', 'LIKE', '%' . $search . '%')
//                         ->orWhereHas('category', function ($q) use ($search) {
//                             $q->where('name', 'LIKE', '%' . $search . '%');
//                         })
//                         ->orWhereHas('subCategory', function ($q) use ($search) {
//                             $q->where('name', 'LIKE', '%' . $search . '%');
//                         })
//                         ->orWhereHas('brand', function ($q) use ($search) {
//                             $q->where('name', 'LIKE', '%' . $search . '%');
//                         })
//                         ->orWhereHas('measureUnit', function ($q) use ($search) {
//                             $q->where('name', 'LIKE', '%' . $search . '%');
//                         })
//                         ->orWhereHas('productType', function ($q) use ($search) {
//                             $q->where('name', 'LIKE', '%' . $search . '%');
//                         })
//                         ->orWhereHas('location', function ($q) use ($search) {
//                             $q->where('name', 'LIKE', '%' . $search . '%');
//                         });
//                 } else {
                    
//                     foreach ($filterOptions as $filter) {
//                         switch (trim($filter)) {
//                             case 'category':
//                                 $q->orWhereHas('category', function ($q) use ($search) {
//                                     $q->where('name', 'LIKE', '%' . $search . '%');
//                                 });
//                                 break;
//                             case 'sub_category':
//                                 $q->orWhereHas('subCategory', function ($q) use ($search) {
//                                     $q->where('name', 'LIKE', '%' . $search . '%');
//                                 });
//                                 break;
//                             case 'brand':
//                                 $q->orWhereHas('brand', function ($q) use ($search) {
//                                     $q->where('name', 'LIKE', '%' . $search . '%');
//                                 });
//                                 break;
//                             case 'measure_unit':
//                                 $q->orWhereHas('measureUnit', function ($q) use ($search) {
//                                     $q->where('name', 'LIKE', '%' . $search . '%');
//                                 });
//                                 break;
//                             case 'product_type':
//                                 $q->orWhereHas('productType', function ($q) use ($search) {
//                                     $q->where('name', 'LIKE', '%' . $search . '%');
//                                 });
//                                 break;
//                             case 'location':
//                                 $q->orWhereHas('location', function ($q) use ($search) {
//                                     $q->where('name', 'LIKE', '%' . $search . '%');
//                                 });
//                                 break;
//                         }
//                     }
//                 }
//             });
//         }

       
//         $products = $query->paginate(50);

//         return response()->json($products);
//     } catch (\Exception $e) {
//         \Log::error('Error fetching products: ' . $e->getMessage());
//         return response()->json(['error' => 'Failed to fetch products'], 500);
//     }
// }

public function index(Request $request): JsonResponse
{
    try {
        // Validate input
        $validator = Validator::make($request->all(), [
            'filter_by' => 'nullable|string',
            'search_name' => 'nullable|string|max:100',
            'search_category' => 'nullable|string|max:100',
            'search_sub_category' => 'nullable|string|max:100',
            'search_brand' => 'nullable|string|max:100',
            'search_measure_unit' => 'nullable|string|max:100',
            'search_product_type' => 'nullable|string|max:100',
            'search_location' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $query = Product::with([
            'category:id,name',
            'subCategory:id,name',
            'brand:id,name',
            'measureUnit:id,name',
            'productType:id,name',
            'location:id,name',
        ]);

        // Filter logic
        $this->applyFilters($query, $request);

        // Pagination
        $perPage = $request->input('per_page', 50);
        $products = $query->paginate($perPage);
        broadcast(new ProductUpdated($products, 'listed'));

        return response()->json($products);

    } catch (\Exception $e) {
        Log::error('Product search error: ' . $e->getMessage(), [
            'exception' => $e,
            'request' => $request->all()
        ]);
        
        return response()->json([
            'error' => 'Server error occurred',
            'details' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

protected function applyFilters($query, Request $request): void
{
    $filterBy = $request->input('filter_by', 'all');
    $filterOptions = array_filter(
        array_map('trim', explode(',', $filterBy))
    );

    $availableFilters = [
        'name' => [
            'param' => 'search_name',
            'query' => fn($q, $v) => $q->where('products.name', 'LIKE', "%{$v}%"),
            'match' => 'products.name LIKE ?'
        ],
        'category' => [
            'param' => 'search_category',
            'query' => fn($q, $v) => $q->whereHas('category', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
            'match' => 'EXISTS (SELECT 1 FROM product_categories WHERE product_categories.id = products.category_id AND product_categories.name LIKE ?)'
        ],
        'sub_category' => [
            'param' => 'search_sub_category',
            'query' => fn($q, $v) => $q->whereHas('subCategory', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
            'match' => 'EXISTS (SELECT 1 FROM product_sub_categories WHERE product_sub_categories.id = products.sub_category_id AND product_sub_categories.name LIKE ?)'
        ],
        'brand' => [
            'param' => 'search_brand',
            'query' => fn($q, $v) => $q->whereHas('brand', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
            'match' => 'EXISTS (SELECT 1 FROM brands WHERE brands.id = products.brand_id AND brands.name LIKE ?)'
        ],
        'measure_unit' => [
            'param' => 'search_measure_unit',
            'query' => fn($q, $v) => $q->whereHas('measureUnit', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
            'match' => 'EXISTS (SELECT 1 FROM measure_units WHERE measure_units.id = products.measure_unit_id AND measure_units.name LIKE ?)'
        ],
        'product_type' => [
            'param' => 'search_product_type',
            'query' => fn($q, $v) => $q->whereHas('productType', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
            'match' => 'EXISTS (SELECT 1 FROM product_types WHERE product_types.id = products.product_type_id AND product_types.name LIKE ?)'
        ],
        'location' => [
            'param' => 'search_location',
            'query' => fn($q, $v) => $q->whereHas('location', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
            'match' => 'EXISTS (SELECT 1 FROM locations WHERE locations.id = products.location_id AND locations.name LIKE ?)'
        ],
    ];

    $activeFilters = empty($filterOptions) || in_array('all', $filterOptions)
        ? array_keys($availableFilters)
        : array_intersect($filterOptions, array_keys($availableFilters));

    $searchTerms = collect($activeFilters)
        ->filter(fn($field) => $request->filled($availableFilters[$field]['param']))
        ->mapWithKeys(fn($field) => [
            $field => $request->input($availableFilters[$field]['param'])
        ]);

    if ($searchTerms->isEmpty()) {
        return;
    }

    // First try exact matching
    $query->where(function ($q) use ($searchTerms, $availableFilters) {
        foreach ($searchTerms as $field => $term) {
            $availableFilters[$field]['query']($q, $term);
        }
    });

    // // Fallback to partial matches if no results
    // if ($query->count() === 0) {
    //     $this->applyPartialMatchFallback($query, $searchTerms, $availableFilters);
    // }
}

protected function applyPartialMatchFallback($query, $searchTerms, $availableFilters): void
{
    $matchExpressions = [];
    $bindings = [];

    foreach ($searchTerms as $field => $term) {
        $matchExpressions[] = $availableFilters[$field]['match'];
        $bindings[] = "%{$term}%";
    }

    $query->selectRaw(
        'products.*, (' . implode(' + ', $matchExpressions) . ') as relevance_score',
        $bindings
    )
    ->where(function ($q) use ($searchTerms, $availableFilters) {
        foreach ($searchTerms as $field => $term) {
            $q->orWhere(function ($q) use ($availableFilters, $field, $term) {
                $availableFilters[$field]['query']($q, $term);
            });
        }
    })
    
    ->orderByDesc('relevance_score')
    // Add secondary sorting criteria for consistent results
    ->orderBy('products.name') // or another unique field like ID
    ->orderBy('products.created_at'); // tertiary sort if needed
}




// public function index(Request $request): JsonResponse
// {
//     try {
//         // Validate input
//         $validator = Validator::make($request->all(), [
//             'filter_by' => 'nullable|string',
//             'search_name' => 'nullable|string|max:100',
//             'search_category' => 'nullable|string|max:100',
//             'search_sub_category' => 'nullable|string|max:100',
//             'search_brand' => 'nullable|string|max:100',
//             'search_measure_unit' => 'nullable|string|max:100',
//             'search_product_type' => 'nullable|string|max:100',
//             'search_location' => 'nullable|string|max:100',
//             'per_page' => 'nullable|integer|min:1|max:100',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'error' => 'Validation failed',
//                 'messages' => $validator->errors()
//             ], 422);
//         }

//         $query = Product::with([
//             'category:id,name',
//             'subCategory:id,name',
//             'brand:id,name',
//             'measureUnit:id,name',
//             'productType:id,name',
//             'location:id,name',
//         ]);

//         // Filter logic
//         $this->applyFilters($query, $request);

//         // Pagination
//         $perPage = $request->input('per_page', 50);
//         $products = $query->paginate($perPage);

//         return response()->json($products);

//     } catch (\Exception $e) {
//         Log::error('Product search error: ' . $e->getMessage(), [
//             'exception' => $e,
//             'request' => $request->all()
//         ]);
        
//         return response()->json([
//             'error' => 'Server error occurred',
//             'details' => config('app.debug') ? $e->getMessage() : null
//         ], 500);
//     }
// }

// protected function applyFilters($query, Request $request): void
// {
//     $filterBy = $request->input('filter_by', 'all');
//     $filterOptions = array_filter(
//         array_map('trim', explode(',', $filterBy))
//     );

//     $availableFilters = [
//         'name' => [
//             'param' => 'search_name',
//             'query' => fn($q, $v) => $q->where('products.name', 'LIKE', "%{$v}%"),
//             'match' => 'products.name LIKE ?'
//         ],
//         'category' => [
//             'param' => 'search_category',
//             'query' => fn($q, $v) => $q->whereHas('category', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
//             'match' => 'EXISTS (SELECT 1 FROM product_categories WHERE product_categories.id = products.category_id AND product_categories.name LIKE ?)'
//         ],
//         'sub_category' => [
//             'param' => 'search_sub_category',
//             'query' => fn($q, $v) => $q->where(function ($q) use ($v) {
//                 $q->whereHas('subCategory', fn($q) => $q->where('name', 'LIKE', "%{$v}%"))
//                   ->orWhereNull('sub_category_id');
//             }),
//             'match' => '(CASE WHEN products.sub_category_id IS NULL THEN 1 ELSE EXISTS (SELECT 1 FROM product_sub_categories WHERE product_sub_categories.id = products.sub_category_id AND product_sub_categories.name LIKE ?) END)'
//         ],
//         'brand' => [
//             'param' => 'search_brand',
//             'query' => fn($q, $v) => $q->whereHas('brand', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
//             'match' => 'EXISTS (SELECT 1 FROM brands WHERE brands.id = products.brand_id AND brands.name LIKE ?)'
//         ],
//         'measure_unit' => [
//             'param' => 'search_measure_unit',
//             'query' => fn($q, $v) => $q->whereHas('measureUnit', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
//             'match' => 'EXISTS (SELECT 1 FROM measure_units WHERE measure_units.id = products.measure_unit_id AND measure_units.name LIKE ?)'
//         ],
//         'product_type' => [
//             'param' => 'search_product_type',
//             'query' => fn($q, $v) => $q->whereHas('productType', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
//             'match' => 'EXISTS (SELECT 1 FROM product_types WHERE product_types.id = products.product_type_id AND product_types.name LIKE ?)'
//         ],
//         'location' => [
//             'param' => 'search_location',
//             'query' => fn($q, $v) => $q->whereHas('location', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
//             'match' => 'EXISTS (SELECT 1 FROM locations WHERE locations.id = products.location_id AND locations.name LIKE ?)'
//         ],
//     ];

//     $activeFilters = empty($filterOptions) || in_array('all', $filterOptions)
//         ? array_keys($availableFilters)
//         : array_intersect($filterOptions, array_keys($availableFilters));

//     $searchTerms = collect($activeFilters)
//         ->filter(fn($field) => $request->filled($availableFilters[$field]['param']))
//         ->mapWithKeys(fn($field) => [
//             $field => $request->input($availableFilters[$field]['param'])
//         ]);

//     if ($searchTerms->isEmpty()) {
//         return;
//     }

//     // First try exact matching
//     $query->where(function ($q) use ($searchTerms, $availableFilters) {
//         foreach ($searchTerms as $field => $term) {
//             $availableFilters[$field]['query']($q, $term);
//         }
//     });

//     // Fallback to partial matches if no results
//     if ($query->count() === 0) {
//         $this->applyPartialMatchFallback($query, $searchTerms, $availableFilters);
//     }
// }

// protected function applyPartialMatchFallback($query, $searchTerms, $availableFilters): void
// {
//     // Reset the query to remove strict intersection conditions
//     $query->getQuery()->wheres = [];
//     $query->getQuery()->bindings['where'] = [];

//     $matchExpressions = [];
//     $bindings = [];

//     foreach ($searchTerms as $field => $term) {
//         $matchExpressions[] = $availableFilters[$field]['match'];
//         $bindings[] = "%{$term}%";
//     }

//     if (!empty($matchExpressions)) {
//         $query->selectRaw(
//             'products.*, (' . implode(' + ', $matchExpressions) . ') as relevance_score',
//             $bindings
//         )
//         ->where(function ($q) use ($searchTerms, $availableFilters) {
//             foreach ($searchTerms as $field => $term) {
//                 $q->orWhere(function ($q) use ($availableFilters, $field, $term) {
//                     $availableFilters[$field]['query']($q, $term);
//                 });
//             }
//         })
//         ->orderByDesc('relevance_score')
//         ->orderBy('products.name')
//         ->orderBy('products.created_at');
//     }
// } 


    

    public function update(Request $request, $id): JsonResponse
    {
        try {

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:products,name,' . $id,
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'category_id' => 'integer|exists:product_categories,id',
                'sub_category_id' => 'integer|exists:product_sub_categories,id',
                'brand_id' => 'integer|exists:brands,id',
                'measure_unit_id' => 'integer|exists:measure_units,id',
                'purchase_rate' => 'numeric',
                'purchase_rate_vat' => 'numeric',
                'retail_sales_price' => 'numeric',
                'retail_sales_price_vat' => 'numeric',
                'retail_sales_price_profit_percent' => 'numeric',
                'wholesales_price' => 'numeric',
                'wholesales_price_vat' => 'numeric',
                'wholesales_price_profit_percent' => 'numeric',
                'is_vatable' => 'boolean',
                'stock_alert' => 'nullable',
                'product_type_id' => 'integer|exists:product_types,id',
                'location_id' => 'integer|exists:locations,id',
                'field_values' => 'array',
                'field_values.*.product_field_id' => 'integer|exists:product_fields,id',
                'field_values.*.value' => 'required|string|max:255',
                'product_list' => 'required|array',
                'product_list.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_list.*.quantity' => 'nullable|integer',
                'product_list.*.barcode' => 'nullable|string|max:255',
                'product_list.*.is_primary' => 'boolean',
                'product_list.*.hs_code' => 'nullable|string|max:255',
                'product_list.*.price' => 'nullable|numeric',
                'product_list.*.discount' => 'nullable|numeric',
                'product_list.*.final_price' => 'nullable|numeric',
                'product_list.*.primary_measure_unit_id' => 'required|integer|exists:measure_units,id',
            ]);

            $product = null;

            DB::transaction(function () use ($validated, $id, &$product) {
                $product = Product::findOrFail($id);
                $product->update($validated);
    
                // Handle field values (your existing code)
                $existingFieldValueIds = $product->productFieldValues()->pluck('id')->toArray();
                $incomingFieldValueIds = collect($validated['field_values'] ?? [])->pluck('id')->filter()->toArray();
    
                foreach ($validated['field_values'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        $fieldValue = ProductFieldValue::find($data['id']);
                        $fieldValue->update([
                            'product_field_id' => $data['product_field_id'],
                            'value' => $data['value'],
                        ]);
                    } else {
                        $product->productFieldValues()->create($data);
                    }
                }
    
                // Delete field values not in request
                $fieldsValuesToDelete = array_diff($existingFieldValueIds, $incomingFieldValueIds);
                ProductFieldValue::whereIn('id', $fieldsValuesToDelete)->delete();
    
                // Handle product list updates
                $existingProductListIds = $product->productList()->pluck('id')->toArray();
                $incomingProductListIds = collect($validated['product_list'] ?? [])->pluck('id')->filter()->toArray();
    
                foreach ($validated['product_list'] ?? [] as $listItem) {
                    if (isset($listItem['id'])) {
                        $productListItem = ProductList::find($listItem['id']);
                        $productListItem->update($listItem);
                        
                        // Handle is_primary logic if needed
                        if ($listItem['is_primary'] ?? false) {
                            $product->productList()
                                ->where('id', '!=', $listItem['id'])
                                ->update(['is_primary' => false]);
                        }
                    } else {
                        $product->productList()->create($listItem);
                    }
                }
    
                // Delete product list items not in request
                $productListToDelete = array_diff($existingProductListIds, $incomingProductListIds);
                ProductList::whereIn('id', $productListToDelete)->delete();
            });
            broadcast(new ProductUpdated($product, 'updated'));
    
            return response()->json(['message' => 'Product Updated', 'product' => $product->load(['productFieldValues', 'productList'])]);
    
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:products,name',
            'is_active' => 'boolean|required',
            'category_id' => 'integer|exists:product_categories,id',
            'sub_category_id' => 'integer|exists:product_sub_categories,id',
            'brand_id' => 'integer|exists:brands,id',
            'measure_unit_id' => 'integer|exists:measure_units,id',
            'purchase_rate' => 'numeric',
            'purchase_rate_vat' => 'numeric',
            'retail_sales_price' => 'numeric',
            'retail_sales_price_vat' => 'numeric',
            'retail_sales_price_profit_percent' => 'numeric',
            'wholesales_price' => 'numeric',
            'wholesales_price_vat' => 'numeric',
            'wholesales_price_profit_percent' => 'numeric',
            'is_vatable' => 'boolean',
            'stock_alert' => 'nullable',
            'product_type_id' => 'integer|exists:product_types,id',
            'location_id' => 'integer|exists:locations,id',
            'field_values' => 'array',
            'field_values.*.product_field_id' => 'integer|exists:product_fields,id',
            'field_values.*.value' => 'required|string|max:255',
            'product_list' => 'required|array',
            'product_list.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'product_list.*.quantity' => 'nullable|integer',
            'product_list.*.barcode' => 'nullable|string|max:255',
            'product_list.*.is_primary' => 'boolean',
            'product_list.*.hs_code' => 'nullable|string|max:255',
            'product_list.*.price' => 'nullable|numeric',
            'product_list.*.discount' => 'nullable|numeric',
            'product_list.*.final_price' => 'nullable|numeric',
            'product_list.*.primary_measure_unit_id' => 'required|integer|exists:measure_units,id',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Product::create($validated);

        if (isset($validated['field_values'])) {
            $item->productFieldValues()->createMany($validated['field_values']);
        }

        if (isset($validated['product_list'])) {
            $item->productList()->createMany($validated['product_list']);
        }
        $broadcast_status = 'initiated';
    try {
        $data = broadcast(new ProductUpdated($item, 'created'));
        \Log::info('ProductUpdated event broadcast initiated', ['product_id' => $item->id]);
    } catch (\Exception $e) {
        $broadcast_status = 'failed';
        \Log::error('ProductUpdated event broadcast failed', [
            'error' => $e->getMessage(),
            'product_id' => $item->id
        ]);
    }

     
      

        return response()->json([
            'item' => $item->load('productList'),
            'action' => 'created',
            'broadcast_status' => $broadcast_status
        ], 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Product::with(['productFieldValues', 'productList'])->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Product::findOrFail($id);
            $item->delete();
            broadcast(new ProductUpdated($product, 'deleted'));
            return response()->json(['message' => 'Product deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function search(){

    }
}
