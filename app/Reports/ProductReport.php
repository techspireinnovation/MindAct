<?php

namespace App\Helpers;

use App\Models\Product;
use Request;



class ProductReport
{
    public static function productListDetails(Request $request)
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
        ]);

        if ($request->has('product_id')) {
            $items->where('id', $request->input('product_id'));
        }
        if ($request->has('brand_id')) {
            $items->where('brand_id', $request->input('brand_id'));
        }

        if ($request->has('product_type_id')) {
            $items->where('product_type_id', $request->input('product_type_id'));
        }

        if ($request->has('sub_category_id')) {
            $items->where('sub_category_id', $request->input('sub_category_id'));
        }
        if ($request->has('location_id')) {
            $items->where('location_id', $request->input('location_id'));
        }
    }
}