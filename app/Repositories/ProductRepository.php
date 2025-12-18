<?php

namespace App\Repositories;

use App\Models\Product;

use App\Interfaces\ProductRepositoryInterface;

use App\Http\Resources\ProductResource;
use DB;

use Illuminate\Support\Arr;

use App\Traits\Paginator;

class ProductRepository implements ProductRepositoryInterface
{
    use Paginator;

    public function applyFilters($query, array $filters)
    {
        if (!empty($filters['search_name'])) {
            $value = $filters['search_name'];
            $query->where(function ($q) use ($value) {
                $q->where('name', 'LIKE', "$value%")
                    ->orWhere('product_code', 'LIKE', "$value%");
                // ->orWhere('barcode', 'LIKE', "%$value%");
            });
        }


        if (!empty($filters['search_product_code'])) {
            $query->where('product_code', 'LIKE', "%{$filters['search_product_code']}%");
        }


        if (!empty($filters['search_category'])) {
            $query->whereHas('category', fn($q) => $q->where('name', 'LIKE', "{$filters['search_category']}%"));
        }

        if (!empty($filters['search_brand'])) {
            $query->whereHas('brand', fn($q) => $q->where('name', 'LIKE', "{$filters['search_brand']}%"));
        }

        if (!empty($filters['search_measure_unit'])) {
            $query->whereHas('measureUnit', fn($q) => $q->where('name', 'LIKE', "{$filters['search_measure_unit']}%"));
        }

        if (!empty($filters['search_product_type'])) {
            $query->whereHas('productType', fn($q) => $q->where('name', 'LIKE', "{$filters['search_product_type']}%"));
        }



        return $query;
    }

    public function search(array $filters)
    {
        $query = Product::select('id', 'name')
            ->with(['category', 'brand', 'measureUnit', 'productType']);

        $query = $this->applyFilters($query, $filters);

        $products = $query->get();

        return new ProductResource($products);
    }

    public function list(array $filters)
    {
        $query = Product::whereNull('deleted_at');
        $query = $this->applyFilters($query, $filters);

        $products = $query->paginate(50);

        return $this->paginated($products, ProductResource::collection($products->items()));
    }




    public function productDetails(array $filters)
    {

        $productId = $filters['product_id'] ?? null;
        $productName = $filters['product_name'] ?? null;

        if ($productId) {
            $productDetail = Product::where('id', $productId)
                ->whereNull('deleted_at')
                ->first();

            if ($productDetail) {
                return $productDetail;
            }
        }


        if ($productName) {
            $productDetail = Product::where('name', $productName)
                ->whereNull('deleted_at')
                ->firstOrFail();
        }


        return new ProductResource($productDetail);

    }



    public function create(array $data): Product
    {


        return DB::transaction(function () use ($data) {

            $product = Product::create($data);



            if (!empty($data['product_list'])) {
                $product->productLists()->createMany($data['product_list']);
            }

            return $product->load('productLists');
        });
    }



    public function update($id, array $data)
    {

        return DB::transaction(function () use ($id, $data) {

            $product = Product::findOrFail($id);

           
            $productData = Arr::except($data, ['product_list']);
            $product->update($productData);

           
            if (isset($data['product_lists'])) {

                $existingIds = $product->productLists()->pluck('id')->toArray();
                $incomingIds = collect($data['product_lists'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                foreach ($data['product_lists'] as $list) {

                    if (!empty($list['id'])) {
                      
                        $product->productLists()
                            ->where('id', $list['id'])
                            ->update(Arr::except($list, ['id']));
                    } else {
                       
                        $product->productLists()->create($list);
                    }
                }

               
                $deleteIds = array_diff($existingIds, $incomingIds);
                if (!empty($deleteIds)) {
                    $product->productLists()->whereIn('id', $deleteIds)->delete();
                }

               
                $primaryId = collect($data['product_lists'])
                    ->where('is_primary', true)
                    ->pluck('id')
                    ->first();

                if ($primaryId) {
                    $product->productLists()
                        ->where('id', '!=', $primaryId)
                        ->update(['is_primary' => false]);
                }
            }

            return $product->fresh('productLists');
        });


    }

    public function delete($id)
    {
        $product = Product::findOrFail($id);

        $usedIn = [];

        if ($product->salesProductFieldValueUse()->exists()) {
            $usedIn[] = 'sales_product_field_values';
        }

        if ($product->saleProductsUse()->exists()) {
            $usedIn[] = 'sale_products';
        }

        if ($product->purchaseProductsUse()->exists()) {
            $usedIn[] = 'purchase_products';
        }



        if ($product->productionSettingsUse()->exists()) {
            $usedIn[] = 'production_settings';
        }



        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $product->delete();
        return true;
    }

    public function show($id)
    {

        $product = Product::with('productLists')->findOrFail($id);

        return new ProductResource($product);
    }




    public function activeProductList()
    {
        $products = Product::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name']);


        $response = ($products->count() > 0) ? ProductResource::collection($products)->map(function ($product) {
            return collect($product)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>