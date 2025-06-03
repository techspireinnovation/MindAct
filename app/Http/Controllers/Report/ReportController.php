<?php

namespace App\Http\Controllers\Report;

use App\Exports\Exports\ProductListDetailsReport;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockEntry;
use Carbon\Carbon;
use DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function productListDetails(Request $request): JsonResponse
    {
        try {
            $items = Product::select("products.id", "is_vatable", "brand_id", "product_type_id", "products.product_unique_id", "sub_category_id", "location_id", "category_id", "products.name")->with([
                'location' => function ($query) use ($request) {
                    return $query->select('locations.id', 'name')->get();
                },
                'category' => function ($query) use ($request) {
                    return $query->select('product_categories.id', 'name')->get();
                },
                'subCategory' => function ($query) use ($request) {
                    return $query->select('product_sub_categories.id', 'name')->get();
                },
                'brand' => function ($query) use ($request) {
                    return $query->select('brands.id', 'name')->get();
                },
                'productType' => function ($query) use ($request) {
                    return $query->select('product_types.id', 'name')->get();
                },
                'latestProduct' => function ($query) use ($request) {
                    return $query->select('product_lists.id', 'product_lists.barcode', 'product_lists.hs_code', 'product_lists.product_id')->get();
                },
                'lastPurchase',
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

            $items = $items->get();
            $items->each->append('product_stock_quantity');

            $items = $items->map(function ($item) {
                $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
                $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
                return $item;
            });
            return response()->json($items);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }

    }
    public function stockRegisterDetails(Request $request): JsonResponse
    {
        //  try {
        $items = Product::select("products.id", "products.product_unique_id", "products.name")->with([
            'lastPurchase',
            'primaryProductItem'
        ]);

        if ($request->has('from_date') && $request->has('to_date')) {
            $items->whereDate('products.created_at', '>=', $request->from_date)->whereDate('products.created_at', '<=', $request->to_date);
        }
        $items = $items->get();
        $items->each->append(['product_stock_quantity', 'opening_quantity', 'purchase_quantity', 'purchase_rate', 'purchase_return_quantity', 'purchase_return_rate', 'sale_quantity', 'sale_rate', 'sale_return_quantity', 'sale_return_rate']);

        $items = $items->map(function ($item) {
            $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
            $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
            return $item;

        });

        //$date = Carbon::now();
        //Excel::store(new ProductListDetailsReport($items), "product-list-{$date}.xlsx");
        return response()->json($items);

        // } catch (\Exception $e) {
        //      \Log::error($e);
        //return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        //}
    }
}
