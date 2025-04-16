<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\ProductList;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
class ProductListController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ProductList::paginate(10));
    }


    public function store(Request $reqeust): JsonResponse
    {
        $validated = $reqeust->validate([
            'product_id' => 'required|integer|exists:products,id',
            'measure_unit_id' => 'required|integer|exists:measure_units,id',
            'company_id' => 'required|integer|exists:companies,id',
            'quantity' => 'nullable|integer',
            'barcode' => 'nullable|string|max:255',
            'hs_code' => 'nullable|string|max:255',
            'price' => 'nullable|numeric',
            'discount' => 'nullable|numeric',
            'final_price' => 'nullable|numeric',
            'primary_measure_unit_id' => 'required|integer|exists:measure_units,id'
        ]);
        $item = ProductList::create($validated);
        return response()->json($item, 201);

    }
   
}
