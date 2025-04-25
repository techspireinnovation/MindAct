<?php

namespace App\Http\Controllers;

use App\Models\SalesReturn;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

class SalesReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SaleReturn::query();
    
        if ($request->has('keywords')) {
            $query->where('batch_no', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'sale_rate_type' => 'required|in:retail,wholesale',
                'return_invoice_number' => 'required|string|max:255',
                'customer_id' => 'required|exists:customers,id',
                'batch_no' => 'string|max:255|unique:sales_returns,batch_no',
                'tpin_number' => 'nullable|string|max:255',
                'sales_id' => 'required|exists:sales,id',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'discount_amount' => 'nullable|numeric',
                'discount_vat' => 'nullable|numeric',
                'paid_amount' => 'nullable|numeric',
                'round_of_amount' => 'nullable|numeric',
                'payment_type' => 'required|in:cash,credit,bank',
                'sales_details' => 'nullable|string',
                'terms' => 'nullable|string',
                'is_active' => 'required|boolean',
            ]);

            $returns = SalesReturn::create($validated);

            return response()->json([
                'message' => 'Sales Return created successfully',
                'data' => $returns,
            ], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $returns = SalesReturn::findOrFail($id);
            return response()->json($returns);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error'], 500);
        }catch(\Exception $e){
            return response()->json(['error' => 'An unexpected error occurred!!']);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'sale_rate_type' => 'required|in:retail,wholesale',
                'return_invoice_number' => 'required|string|max:255',
                'customer_id' => 'required|exists:customers,id',
                'batch_no' => 'string|max:255|unique|sales_returns,batch_no,' . $id,
                'tpin_number' => 'nullable|string|max:255',
                'sales_id' => 'required|exists:sales,id',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'discount_amount' => 'nullable|numeric',
                'discount_vat' => 'nullable|numeric',
                'paid_amount' => 'nullable|numeric',
                'round_of_amount' => 'nullable|numeric',
                'payment_type' => 'required|in:cash,credit,bank',
                'sales_details' => 'nullable|string',
                'terms' => 'nullable|string',
                'is_active' => 'required|boolean',
            ]);

            $returns = SalesReturn::findOrFail($id);
            $returns->update($validated);

            return response()->json([
                'message' => 'Sales Return updated successfully',
                'data' => $returns,
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error'], 500);
        }catch (\Exception $e){
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred!!']);

        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = SalesReturn::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Sales Return deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error'], 500);
        }
    }
}
