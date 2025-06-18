<?php

namespace App\Reports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;


class ProductReport
{
    public static function productListDetails(array $request): Builder
    {
        $items = Product::select("products.id", "is_vatable", "brand_id", "product_type_id", "products.product_unique_id", "sub_category_id", "location_id", "category_id", "products.name")->with([
            'location' => function ($query) {
                return $query->select('locations.id', 'name')->get();
            },
            'category' => function ($query) {
                return $query->select('product_categories.id', 'name')->get();
            },
            'subCategory' => function ($query) {
                return $query->select('product_sub_categories.id', 'name')->get();
            },
            'brand' => function ($query) {
                return $query->select('brands.id', 'name')->get();
            },
            'productType' => function ($query) {
                return $query->select('product_types.id', 'name')->get();
            },
            'primaryProductItem'
        ]);

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

        return $items;
    }

    public static function stockRegisterListDetails(array $request): Builder
    {

    }
}
