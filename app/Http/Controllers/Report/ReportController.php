<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\StockEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function stockRegisterDetails(Request $request): JsonResponse
    {
        try {
            $items = StockEntry::select("id", "product_id", "uom", "quantity", "rate", "amount")->with([
                'product' => function ($query) {
                    $query->select('id', 'name');
                }
            ]);
            return response()->json($items->paginate(50));

        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        }
    }
}
