<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\StockEntry;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
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
