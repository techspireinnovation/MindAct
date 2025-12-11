<?php

namespace App\Repositories;

use App\Models\Product;

use App\Interfaces\ProductRepositoryInterface;

class ProductRepository implements ProductRepositoryInterface
{

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

        // if (!empty($filters['search_barcode'])) {
        //     $query->where('barcode', 'LIKE', "%{$filters['search_barcode']}%");
        // }

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

        return $query->get();
    }

    public function list(array $filters, int $perPage = 50)
    {
        $query = Product::whereNull('deleted_at');
        $query = $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }


   

    public function productDetails($productId = Null, $productName = Null)
    {

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


        return $productDetail;

    }



    public function create(array $data): Product
    {


        return Product::create($data);

    }



    public function update($id, array $data)
    {

        $product = Product::findOrFail($id);

        $product->update($data);

        return $product->fresh();


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

        $product = Product::findOrFail($id);

        return $product;
    }




    public function activeProductList()
    {
        $products = Product::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name']);


        return $products;

    }




}
?>