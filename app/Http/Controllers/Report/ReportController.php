<?php

namespace App\Http\Controllers\Report;

use App\Exports\Exports\ProductListDetailsReport;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseProduct;
use App\Models\SaleProduct;
use App\Models\StockEntry;
use Carbon\Carbon;
use DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Pratiksh\Nepalidate\Services\NepaliDate;
use Validator;


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
            \Illuminate\Support\Facades\Log::error($e);
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
        $items->where('id', $request->input('product_id'));


        $items = $items->get();
        $items->each->append(['product_stock_quantity', 'opening_quantity', 'purchase_quantity', 'purchase_rate', 'purchase_return_quantity', 'purchase_return_rate', 'sale_quantity', 'sale_rate', 'sale_return_quantity', 'sale_return_rate', 'stock_adjustment_quantity', 'stock_in_quantity', 'stock_out_quantity']);

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

    public function productPriceListDetails(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), [

            'type' => 'required|string|in:purchase,sales',
            'product_id' => 'required|numeric',

        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->type === "purchase") {
            $items = PurchaseProduct::select("purchase_products.id", "purchase_products.customer_id", "purchase_products.product_id", "purchase_products.created_at", "purchase_products.purchase_id")->with(['purchase:id,customer_id,purchase_bill_number', 'purchase.customer:id,party_name'])->where('product_id', $request->product_id);

            if ($request->has('customer_id')) {
                $items->where('customer_id', $request->input('customer_id'));
            }

            if ($request->has('from_date') && $request->has('to_date')) {
                $items->whereDate('purchase_products.created_at', '>=', $request->from_date)->whereDate('purchase_products.created_at', '<=', $request->to_date);
            }

            $items = $items->get();
            $items->each->append(['purchase_quantity', 'purchase_unit', 'purchase_rate', 'purchase_discount_amount']);
        } else {
            $items = SaleProduct::select("sale_products.id", "sale_products.product_id", "sale_products.product_id", "sale_products.created_at", "sale_products.sale_id")->with([
                'sale' => function ($q) {
                    $q->select("sales.id", "sales.customer_id", "sales.invoice_number", "sales.invoice_date_bs")->with([
                        "customer" => function ($cus) {
                            $cus->select("customers.id", "customers.party_name");
                        }
                    ]);
                }
            ])->whereHas('sale', function ($query) use ($request) {
                if ($request->has('from_date_bs') && $request->has('to_date_bs')) {
                    $query->whereDate('invoice_date_bs', '>=', $request->from_date_bs)->whereDate('invoice_date_bs', '<=', $request->to_date_bs);
                }
            })->where('product_id', $request->product_id);

            if ($request->has('customer_id')) {
                $items->where('customer_id', $request->input('customer_id'));
            }

            if ($request->has('from_date') && $request->has('to_date')) {
                $items->whereDate('sale_products.created_at', '>=', $request->from_date)->whereDate('sale_products.created_at', '<=', $request->to_date);
            }

            $items = $items->get();
            $items->each->append(['sale_quantity', 'sale_unit', 'sale_rate', 'sale_discount_amount']);
        }

        return response()->json($items);


    }


}
