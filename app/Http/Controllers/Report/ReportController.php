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
    public function productListDetails(Request $request): mixed
    {
        // try {
        $items = Product::select("products.id", "products.id AS quantity", "is_vatable", "brand_id", "product_type_id", "products.product_unique_id", "sub_category_id", "location_id", "category_id", "products.name")->with([
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

        if ($request->has('from_date') && $request->has('to_date')) {
            $items->whereDate('products.created_at', '>=', $request->from_date)->whereDate('products.created_at', '<=', $request->to_date);
        }
        $items = $items->get();
        $items->each->append('product_stock_quantity');

        $items = $items->map(function ($item) {
            $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
            $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
            return $item;

        });

        //$date = Carbon::now();
        //Excel::store(new ProductListDetailsReport($items), "product-list-{$date}.xlsx");
        return response()->json($items);

        //  } catch (\Exception $e) {
        //    \Log::error($e);
        //  return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        //}

    }
    public function stockRegisterDetails(Request $request): JsonResponse
    {
        //  try {
        $query = StockEntry::select("stock_entries.id", "stock_entries.product_id", "stock_entries.uom", "stock_entries.quantity", "stock_entries.rate", "stock_entries.amount", DB::raw('COALESCE(SUM(purchase_products.quantity),0) as purchase_quantity,COALESCE(AVG(purchase_products.price),0) as purchase_rate'), DB::raw('COALESCE(SUM(sale_products.quantity),0) as sale_quantity,COALESCE(AVG(sale_products.price),0) as sale_rate'), DB::raw('COALESCE(SUM(purchase_product_returns.quantity),0) as purchase_return_quantity,COALESCE(AVG(purchase_product_returns.price),0) as purchase_return_rate'), DB::raw('COALESCE(SUM(sales_return_products.quantity),0) as sale_return_quantity,COALESCE(AVG(sales_return_products.price),0) as sale_return_rate'))->leftJoin('purchase_products', 'stock_entries.product_id', '=', 'purchase_products.product_id')->leftJoin('sales_return_products', 'stock_entries.product_id', '=', 'sales_return_products.product_id')->leftJoin('purchase_product_returns', 'stock_entries.product_id', '=', 'purchase_product_returns.product_id')->leftJoin('sale_products', 'stock_entries.product_id', '=', 'sale_products.product_id')->with([
            'product' => function ($query) use ($request) {
                $query->select('id', 'name');
                // if ($request->has('from_date') && $request->has('to_date')) {
                //   $query->whereDate('created_at', '>=', $request->from_date)->whereDate('created_at', '<=', $request->to_date);
                // }
            }
        ]);

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereDate('stock_entries.created_at', '>=', $request->from_date)->whereDate('stock_entries.created_at', '<=', $request->to_date);
        }

        $query->groupBy("stock_entries.id", "stock_entries.product_id");

        //  dd($query->toSql());
        return response()->json($query->paginate(50));

        // } catch (\Exception $e) {
        //      \Log::error($e);
        //return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        //}
    }
}
