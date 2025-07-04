<?php

namespace App\Reports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Pratiksh\Nepalidate\Services\EnglishDate;


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

        if (isset($request['company_id'])) {
            $items->where('company_id', operator: $request['company_id']);
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

        if (isset($request['category_id'])) {
            $items->where('category_id', $request['category_id']);
        }
        if (isset($request['location_id'])) {
            $items->where('location_id', $request['location_id']);
        }

        if (isset($request['is_vatable'])) {
            $items->where('is_vatable', $request['is_vatable']);
        }

        return $items;
    }

    public static function stockRegisterListDetails(array $request): Builder
    {
        $items = Product::select("products.id", "products.product_unique_id", "products.is_vatable", "products.name", "products.product_type_id", "products.location_id", "products.name", "products.brand_id", "products.is_vatable", "products.category_id", "products.sub_category_id")->with([
            'lastPurchase',
            'primaryProductItem',
            'category:id,name',
            'location:id,name',
            'subCategory:id,name',
            'brand:id,name',
            'productType:id,name',
        ]);

        if (isset($request['company_id'])) {
            $items->where('company_id', operator: $request['company_id']);
        }

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

        if (isset($request['category_id'])) {
            $items->where('category_id', $request['category_id']);
        }
        if (isset($request['location_id'])) {
            $items->where('location_id', $request['location_id']);
        }

        if (isset($request['is_vatable'])) {
            $items->where('is_vatable', $request['is_vatable']);
        }

        if (isset($request['from_date']) && isset($request['to_date'])) {
            // Convert to English date
            $englishDateFrom = EnglishDate::create($request['from_date'])->toAD();
            $englishDateTo = EnglishDate::create($request['to_date'])->toAD();
            $items->whereDate('created_at', '>=', $englishDateFrom)->whereDate('created_at', '<=', $englishDateTo);
        }

        return $items;

    }
}
