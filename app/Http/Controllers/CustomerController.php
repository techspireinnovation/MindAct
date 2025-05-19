<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();
    
        if ($request->has('keywords')) {
            $query->where('party_name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'party_name' => 'required|string|max:255|unique:customers,party_name',
                'pan_number' => 'nullable|string|unique:customers,pan_number',
                'billing_address' => 'nullable|numeric',
                'opening_balance' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'ledger_type' => 'required|in:customer,vendor,both',
                'address' => 'nullable|string',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|unique:customers,email|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_phone' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'area' => 'nullable|string|max:100',
                'bank_name' => 'nullable|string|max:255',
                'bank_account_number' => 'nullable|string|max:255',
                'is_active' => 'required|boolean',
            ]);
    
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
    
            $customer = Customer::create($validator->validated());
    
            return response()->json([
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
    
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }
    
    

public function show($id):JsonResponse
{
    try {
        $item = Customer::findOrFail($id);
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
            'party_name' => 'required|string|max:255|unique:customers,party_name,' . $id,
            'pan_number' => 'nullable|string|unique:customers,pan_number,' . $id,
            'ledger_type' => 'required|in:customer,vendor,both',
            'address' => 'nullable|string',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|unique:customers,email,' . $id,
            'contact_person' => 'nullable|string|max:255',
            'contact_person_phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $customer = Customer::findOrFail($id);
        $customer->update($validator->validated());

        return response()->json([
            'message' => 'Customer updated successfully',
            'data' => $customer
        ], 200);

    } catch (ModelNotFoundException $e) {
        \Log::error($e);
        return response()->json(['error' => 'Customer not found.'], 404);
    } catch (QueryException $e) {
        \Log::error($e);
        return response()->json(['error' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error($e);
        return response()->json(['error' => 'Unexpected error occurred.'], 500);
    }
}




    public function destroy($id): JsonResponse
    {
        try {
            $item = Customer::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Customer deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Customer not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
