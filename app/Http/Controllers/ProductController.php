<?php

namespace App\Http\Controllers;


use App\Events\ProductUpdated;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\ProductFieldValue;
use App\Models\ProductList;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function generateProductID(): JsonResponse
    {
        $latestProduct = Product::withTrashed()->orderBy('id', 'desc')->first();
        $nextNumber = $latestProduct ? $latestProduct->id + 1 : 1;
        $productID = 'PID-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

        while (Product::withTrashed()->where('product_unique_id', $productID)->exists()) {
            $nextNumber++;
            $productID = 'PID-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
        }

        return response()->json(['product_id' => $productID]);
    }

    public function getProductNames()
    {

        try {
            $productNames = Product::where('is_active', true)
                ->whereNull('deleted_at')
                ->pluck('name');
            return $productNames;
        } catch (\Exception $e) {
            Log::error('Error fetching product names: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return response()->json([
                'error' => 'Server error occurred while fetching product names',
                'details' => config('app.debug') ? $e->getMessage() : null
            ], 500);

        }
    }

    public function getProductDetailsByNames(Request $request): JsonResponse
    {
        try {
            $name = $request->input('name');
            if (!$name) {
                return response()->json(['error' => 'Name parameter is required'], 422);
            }
            $productNames = Product::with([
                'productLists',
                'productFieldValues.productField'
            ])
                ->where('name', $name)
                ->whereNull('deleted_at')
                ->firstorFail();

            $valuesByFieldId = $productNames->productFieldValues->keyBy('product_field_id');
            $productFields = ProductField::where('company_id', $productNames->company_id);
            $productFields = $productFields->whereIn('id', $valuesByFieldId->keys())
                ->get();
            $productFields = $productFields->map(function ($field) use ($valuesByFieldId) {
                $fieldArray = [
                    'product_field_id' => $field->id, // Rename id to product_field_id
                    'company_id' => $field->company_id,
                    'name' => $field->name,
                    'type' => $field->type,
                    'values' => $field->values,
                    'is_active' => $field->is_active,
                    'deleted_at' => $field->deleted_at,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at,
                    'product_field_value' => $valuesByFieldId->get($field->id)?->only(['id', 'value', 'created_at', 'updated_at'])
                ];
                return $fieldArray;
            });
            $productNames = $productNames->toArray();
            unset($productNames['product_field_values']);
            $productNames['product_fields'] = $productFields;
            return response()->json([
                'product' => $productNames
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database query error occurred!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'filter_by' => 'nullable|string',
                'search_name' => 'nullable|string|max:100',
                'search_category' => 'nullable|string|max:100',
                'search_sub_category' => 'nullable|string|max:100',
                'search_brand' => 'nullable|string|max:100',
                'search_measure_unit' => 'nullable|string|max:100',
                'search_product_type' => 'nullable|string|max:100',
                'search_location' => 'nullable|string|max:100',
                'search_product_field' => 'nullable|string|max:100',
                'search_product_field_value' => 'nullable|string|max:100',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => $validator->errors()
                ], 422);
            }

            // Query products with relationships
            $query = Product::with([
                'category:id,name',
                'subCategory:id,name',
                'brand:id,name',
                'measureUnit:id,name',
                'productType:id,name',
                'location:id,name',
                'productLists',
                'productFieldValues.productField'
            ]);

            // Apply filters
            $this->applyFilters($query, $request);

            // Pagination
            $perPage = $request->input('per_page', 50);
            $products = $query->paginate($perPage);

            // Transform products to include product_fields and exclude product_field_values
            $transformedProducts = $products->through(function ($product) {
                // Group values by field ID
                $valuesByFieldId = $product->productFieldValues->keyBy('product_field_id');

                // Get only product fields with values for this product
                $productFields = ProductField::where('company_id', $product->company_id)
                    ->whereIn('id', $valuesByFieldId->keys())
                    ->get();

                // Build response fields with values embedded
                $product_fields = $productFields->map(function ($field) use ($valuesByFieldId) {
                    $fieldArray = $field->toArray();
                    $fieldArray['product_field_value'] = $valuesByFieldId->get($field->id)?->only(['id', 'value', 'created_at', 'updated_at']);
                    return $fieldArray;
                });

                // Prepare product response without product_field_values
                $productArray = $product->toArray();
                unset($productArray['product_field_values']);

                // Add product_fields to the product array
                $productArray['product_fields'] = $product_fields;

                return $productArray;
            });

            // Broadcast event
            broadcast(new ProductUpdated($products, 'listed'));

            // Return paginated response with transformed data
            return response()->json([
                'data' => $transformedProducts->items(),
                'pagination'=>

                [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total()
                
                ]
            ]);

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
            'product_field' => [
                'param' => 'search_product_field',
                'query' => fn($q, $v) => $q->whereHas('productFieldValues.productField', fn($q) => $q->where('name', 'LIKE', "%{$v}%")),
                'match' => 'EXISTS (SELECT 1 FROM product_field_values INNER JOIN product_fields ON product_field_values.product_field_id = product_fields.id WHERE product_field_values.product_id = products.id AND product_fields.name LIKE ?)'
            ],
            'product_field_value' => [
                'param' => 'search_product_field_value',
                'query' => fn($q, $v) => $q->whereHas('productFieldValues', fn($q) => $q->where('value', 'LIKE', "%{$v}%")),
                'match' => 'EXISTS (SELECT 1 FROM product_field_values WHERE product_field_values.product_id = products.id AND product_field_values.value LIKE ?)'
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

        // Apply filters
        $query->where(function ($q) use ($searchTerms, $availableFilters) {
            foreach ($searchTerms as $field => $term) {
                $availableFilters[$field]['query']($q, $term);
            }
        });
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


    public function filterbyBarcode(Request $request): JsonResponse
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'barcode' => 'required|exists:product_lists,barcode',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $barcode = $request->input('barcode');

            // Fetch ProductList entries with the given barcode and load the product with all relationships
            $productLists = ProductList::with([
                'product' => function ($query) {
                    $query->with([
                        'category',
                        'subCategory',
                        'brand',
                        'measureUnit',
                        'productType',
                        'location',
                        'productFieldValues',
                        'productLists'
                    ]);
                }
            ])->where('barcode', $barcode)->get();

            if ($productLists->isEmpty()) {
                return response()->json(['error' => 'No products found for this barcode'], 404);
            }

            // Map to products, ensuring each product is only included once
            $products = $productLists->pluck('product')->unique('id')->values();

            return response()->json(['data' => $products]);

        } catch (QueryException $e) {
            \Log::error('Database error in filterbyBarcode: ' . $e->getMessage());

            return response()->json(['error' => 'Database error'], 500);
        } catch (\Exception $e) {

            \Log::error('Server error in filterbyBarcode: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Product::findOrFail($id);
            $rules = [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('products')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'is_active' => 'boolean|required',
                'product_unique_id' => 'string|max:255|unique:products,product_unique_id,' . $id,
                'company_id' => 'integer|exists:companies,id',
                'category_id' => 'integer|nullable|',
                'sub_category_id' => 'integer|nullable|',
                'is_fixed_amount' => 'boolean|nullable',
                'brand_id' => 'integer|nullable|',
                'measure_unit_id' => 'integer|exists:measure_units,id',
                'purchase_rate' => 'numeric|nullable|',
                'purchase_rate_vat' => 'numeric|nullable|',
                'retail_sales_price' => 'numeric|nullable|',
                'retail_sales_price_vat' => 'numeric|nullable|',
                'retail_sales_price_profit_percent' => 'numeric|nullable|',
                'wholesales_price' => 'numeric|nullable|',
                'wholesales_price_vat' => 'numeric|nullable|',
                'wholesales_price_profit_percent' => 'numeric|nullable|',
                'is_vatable' => 'boolean|nullable',
                'stock_alert' => 'nullable',
                'product_type_id' => 'integer|nullable|',
                'location_id' => 'integer|nullable|',
                'field_values' => 'array',
                'field_values.*.product_field_id' => 'integer|nullable',
                'field_values.*.value' => 'nullable|string|max:255',
                'product_list' => 'nullable|array',
                'product_list.*.id' => 'nullable|nullable',
                'product_list.*.product_unique_id' => 'nullable',
                'product_list.*.measure_unit_id' => 'nullable|integer|exists:measure_units,id',
                'product_list.*.quantity' => 'nullable|integer',
                'product_list.*.barcode' => 'nullable|string|max:255', // Removed unique rule
                'product_list.*.is_primary' => 'boolean',
                'product_list.*.hs_code' => 'nullable|string|max:255',
                'product_list.*.price' => 'nullable|numeric',
                'product_list.*.discount' => 'nullable|numeric',
                'product_list.*.final_price' => 'nullable|numeric',
                'product_list.*.primary_measure_unit_id' => 'nullable|integer|exists:measure_units,id',
            ];


            $validator = Validator::make($request->all(), $rules, [
                'product_list.*.barcode' => 'The barcode has already been taken.',
            ]);

            // Custom barcode validation
            $validator->after(function ($validator) use ($request) {
                $productLists = $request->input('product_list', []);

                foreach ($productLists as $index => $listItem) {
                    $barcode = data_get($listItem, 'barcode');
                    $productListId = data_get($listItem, 'id');

                    if ($barcode && $productListId) {
                        $existingProductList = ProductList::find($productListId);
                        if ($existingProductList && $existingProductList->barcode === $barcode) {
                            continue;
                        }
                    }

                    if ($barcode) {
                        $existing = ProductList::where('barcode', $barcode)
                            ->when($productListId, fn($query) => $query->where('id', '!=', $productListId))
                            ->first();

                        if ($existing) {
                            $validator->errors()->add(
                                "product_list.{$index}.barcode",
                                'The barcode has already been taken.'
                            );
                        }
                    }
                }
            });

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $product = null;

            DB::transaction(function () use ($validated, $id, &$product) {
                $product = Product::findOrFail($id);
                $product->update($validated);

                // Handle field values
                $existingFieldValueIds = $product->productFieldValues()->pluck('id')->toArray();
                $incomingFieldValueIds = collect($validated['field_values'] ?? [])->pluck('id')->filter()->toArray();

                foreach ($validated['field_values'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        $fieldValue = ProductFieldValue::find($data['id']);
                        if ($fieldValue) {
                            $fieldValue->update([
                                'product_field_id' => $data['product_field_id'],
                                'value' => $data['value'],
                            ]);
                        }
                    } else {
                        $product->productFieldValues()->create($data);
                    }
                }

                $fieldsValuesToDelete = array_diff($existingFieldValueIds, $incomingFieldValueIds);
                ProductFieldValue::whereIn('id', $fieldsValuesToDelete)->delete();

                // Handle product list
                $existingProductListIds = $product->productLists()->pluck('id')->toArray();
                $incomingProductListIds = collect($validated['product_list'] ?? [])->pluck('id')->filter()->toArray();

                foreach ($validated['product_list'] ?? [] as $listItem) {
                    if (isset($listItem['id'])) {
                        $productListItem = ProductList::find($listItem['id']);
                        if ($productListItem) {
                            $productListItem->update($listItem);

                            if ($listItem['is_primary'] ?? false) {
                                $product->productLists()
                                    ->where('id', '!=', $listItem['id'])
                                    ->update(['is_primary' => false]);
                            }
                        }
                    } else {
                        $product->productLists()->create($listItem);
                    }
                }

                $productListToDelete = array_diff($existingProductListIds, $incomingProductListIds);
                ProductList::whereIn('id', $productListToDelete)->delete();
            });

            broadcast(new ProductUpdated($product, 'updated'));

            return response()->json(['message' => 'Product Updated', 'product' => $product->load(['productFieldValues', 'productLists'])]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');

                }),
            ],
            'is_active' => 'boolean|required',
            'category_id' => 'integer|nullable',
            'is_fixed_amount' => 'boolean|nullable',
            'product_unique_id' => 'string|max:255|unique:products',
            'sub_category_id' => 'integer|nullable',
            'brand_id' => 'integer|nullable',
            'measure_unit_id' => 'integer|exists:measure_units,id',
            'purchase_rate' => 'nullable|numeric',
            'purchase_rate_vat' => 'nullable|numeric',
            'retail_sales_price' => 'nullable|numeric',
            'retail_sales_price_vat' => 'nullable|numeric',
            'retail_sales_price_profit_percent' => 'nullable|numeric',
            'wholesales_price' => 'nullable|numeric',
            'wholesales_price_vat' => 'nullable|numeric',
            'wholesales_price_profit_percent' => 'nullable|numeric',
            'is_vatable' => 'nullable|boolean',
            'stock_alert' => 'nullable',
            'product_type_id' => 'integer|nullable|',
            'location_id' => 'integer|nullable|',
            'field_values' => 'array|nullable|',
            'field_values.*.product_field_id' => 'integer|nullable|',
            'field_values.*.value' => 'nullable||string|max:255',
            'product_list' => 'nullable||array',
            'product_list.*.id' => 'nullable',
            'product_list.*.product_unique_id' => 'nullable',
            'product_list.*.measure_unit_id' => 'nullable||integer|exists:measure_units,id',
            'product_list.*.quantity' => 'nullable|integer',
            'product_list.*.barcode' => 'nullable|string|max:255|unique:product_lists,barcode',
            'product_list.*.is_primary' => 'boolean|nullable|',
            'product_list.*.hs_code' => 'nullable|string|max:255',
            'product_list.*.price' => 'nullable|numeric',
            'product_list.*.discount' => 'nullable|numeric',
            'product_list.*.final_price' => 'nullable|numeric',
            'product_list.*.primary_measure_unit_id' => 'nullable||integer|exists:measure_units,id',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Product::create($validated);

        if (isset($validated['field_values'])) {
            $item->productFieldValues()->createMany($validated['field_values']);
        }

        if (isset($validated['product_list'])) {
            $item->productLists()->createMany($validated['product_list']);
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
            'item' => $item->load('productLists'),
            'action' => 'created',
            'broadcast_status' => $broadcast_status
        ], 201);
    }


    public function show(Request $request, $id): JsonResponse
    {
        try {
            $product = Product::where('company_id', $request->company_id)
                ->with([
                    'productLists',
                    'productFieldValues.productField'
                ])
                ->findOrFail($id);

            // Group values by field ID
            $valuesByFieldId = $product->productFieldValues->keyBy('product_field_id');

            // Get only product fields with values for this product
            $productFields = ProductField::where('company_id', $product->company_id)
                ->whereIn('id', $valuesByFieldId->keys())
                ->get();

            // Build response fields with values embedded
            $product_fields = $productFields->map(function ($field) use ($valuesByFieldId) {
                $fieldArray = [
                    'product_field_id' => $field->id, // Rename id to product_field_id
                    'company_id' => $field->company_id,
                    'name' => $field->name,
                    'type' => $field->type,
                    'values' => $field->values,
                    'is_active' => $field->is_active,
                    'deleted_at' => $field->deleted_at,
                    'created_at' => $field->created_at,
                    'updated_at' => $field->updated_at,
                    'product_field_value' => $valuesByFieldId->get($field->id)?->only(['id', 'value', 'created_at', 'updated_at'])
                ];
                return $fieldArray;
            });

            // Prepare product response without product_field_values
            $productArray = $product->toArray();
            unset($productArray['product_field_values']);
            $productArray['product_fields'] = $product_fields;

            // Rename 'product_lists' to 'product_list' in the response
            if (isset($productArray['product_lists'])) {
                $productArray['product_list'] = $productArray['product_lists'];
                unset($productArray['product_lists']);
            }

            return response()->json([
                'product' => $productArray
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Product::findOrFail($id);


            $hasPurchases = DB::table('purchase_products')->where('product_id', $id)->whereNull('deleted_at')->exists();
            $hasSales = DB::table('sale_products')->where('product_id', $id)->whereNull('deleted_at')->exists();

            if ($hasPurchases || $hasSales) {
                return response()->json([
                    'error' => 'Cannot delete product because it is associated with purchases or sales.'
                ], 422);

            }
            $item->delete();
            broadcast(new ProductUpdated($item, 'deleted'));
            return response()->json(['message' => 'Product deleted!!']);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}
