<?php

namespace App\Reports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;


class ProductReport
{
    public static function productListDetails(Request $request): mixed
    {
        $params = $request->query();
        ksort($params);
        $queryString = http_build_query($params);
        $cacheKey = 'productList.' . sha1($queryString);
        $products = Product::cache()->get($cacheKey);
        return $products;

        /*
        if (isset($request['product_id'])) {
            $items->where('id', operator: $request['product_id']);
        }
        if (isset($request['brand_id'])) {
            $items->where('brand_id', $request['brand_id']);
        }
        if (isset($request['product_type_id'])) {
            $items->where('product_type_id', $request['product_type_id']);
        }

        if (isset($request['sub_category_id'])) {
            $items->where('sub_category_id', $request['sub_category_id']);
        }
        if (isset($request['location_id'])) {
            $items->where('location_id', $request['location_id']);
        }
*/

    }

    public static function stockRegisterListDetails(array $request): Builder
    {
        $items = Product::select("products.id", "products.product_unique_id", "products.is_vatable", "products.name", "products.product_type_id", "products.location_id", "products.name", "products.brand_id", "products.category_id", "products.sub_category_id")->with([
            'lastPurchase',
            'primaryProductItem',
            'category:id,name',
            'location:id,name',
            'subCategory:id,name',
            'brand:id,name',
            'productType:id,name',
        ]);

        if (isset($request['from_date']) && isset($request['to_date'])) {
            $items->whereDate('products.created_at', '>=', $request['from_date'])->whereDate('products.created_at', '<=', $request['to_date']);
        }
        return $items;

    }
}
