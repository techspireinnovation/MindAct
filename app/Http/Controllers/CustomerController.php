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
    public function index(): JsonResponse
    {
        return response()->json(Customer::paginate(10));
    }
    

    public function store(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:customers,email|max:255',
            'address' => 'nullable|string',
            'pan_vat_number' => 'nullable|string|max:50',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        $customer = Customer::create($validated);

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => $customer
        ], 201);

    } catch (QueryException $e) {
        dd($e->getMessage());
        return response()->json(['error' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
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
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:customers,email,' . $id,
            'address' => 'nullable|string',
            'pan_vat_number' => 'nullable|string|max:50',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        $customer = Customer::findOrFail($id);
        $customer->update($validated);

        return response()->json([
            'message' => 'Customer updated successfully',
            'data' => $customer
        ], 200);

    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Customer not found.'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
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
