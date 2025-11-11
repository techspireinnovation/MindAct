<?php

namespace App\Http\Controllers;
use App\Models\SalesReturn;
use App\Models\SalesReturnProduct;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SalesReturn::paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|',
                'sale_rate_type' => 'required|in:retail,wholesale',
                'return_invoice_number' => 'required|string|max:255',
                'expiry_date' => 'nullable|date',
                'customer_id' => 'required|exists:customers,id',
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
        } catch (\Exception $e) {
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
                'expiry_date' => 'nullable|date',
                'customer_id' => 'required|exists:customers,id',
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
        } catch (\Exception $e) {

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
