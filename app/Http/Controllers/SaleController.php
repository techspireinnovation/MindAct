<?php

namespace App\Http\Controllers;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Sale::paginate(10));
    }

    

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'store_id' => 'required|integer',
                'entry_type' => 'required|in:invoice,quotation',
                'note' => 'nullable|string|max:255',
                'invoice_quotation_number' => 'required|string|max:255',
                'customer_id' => 'required|numeric|exists:customers,id',
              
                'bill_number' => 'nullable|string|max:255',
                'tpin_number' => 'nullable|string|max:255',
                'billing_date' => 'required|date',
                'location' => 'nullable|exists:locations,id',
                'sale_rate_type' => 'required|in:retail,wholesale',
                'discount' => 'nullable|numeric',
                'discount_vat' => 'nullable|numeric',
                'paid_amount' => 'nullable|numeric',
                'round_of_amount' => 'nullable|numeric',
                'payment_type' => 'required|in:cash,credit,bank',
                'is_active' => 'required|boolean',
            ]);
    
            $sale = Sale::create($validated);
    
            return response()->json([
                'message' => 'Sale created successfully',
                'data' => $sale
            ], 201);
    
        } catch(ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        }
        catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }
    

public function show($id):JsonResponse
{
    try {
        $item = Sale::findOrFail($id);
        return response()->json($item);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Item not found'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}

public function update(Request $request, $id): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'store_id' => 'required|integer',
            'entry_type' => 'required|in:invoice,quotation',
            'note' => 'nullable|string|max:255',
            'invoice_quotation_number' => 'required|string|max:255',
            'customer_id' => 'required|numeric|exists:customers,id',
          
            'bill_number' => 'nullable|string|max:255',
            'tpin_number' => 'nullable|string|max:255',
            'billing_date' => 'required|date',
            'location' => 'nullable|exists:locations,id',
            'sale_rate_type' => 'required|in:retail,wholesale',
            'discount' => 'nullable|numeric',
            'discount_vat' => 'nullable|numeric',
            'paid_amount' => 'nullable|numeric',
            'round_of_amount' => 'nullable|numeric',
            'payment_type' => 'required|in:cash,credit,bank',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        $sale = Sale::findOrFail($id);
        $sale->update($validated);

        return response()->json([
            'message' => 'Sale updated successfully',
            'data' => $sale
        ], 200);

    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Sale not found.'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Unexpected error occurred.'], 500);
    }
}






    public function destroy($id): JsonResponse
    {
        try {
            $item = Sale::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Sale deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
