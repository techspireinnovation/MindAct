<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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


    public function customerList(Request $request)
    {
        try {

            $customer = Customer::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->pluck('party_name');
            return response()->json([
                "message" => "Customer List Received !!",
                "data" => $customer
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);

            return response()->json(["error" => "Item not Found !!"], 404);
        } catch (QueryExceptioon $e) {

            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function customerDetails(Request $request)
    {
        try {

            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $customer = $request->customer_name;
            $customerDetails = Customer::where('company_id', $request->company_id)
                ->where('party_name', $customer)
                ->whereNull('deleted_at')
                ->firstorFail();
            return response()->json([
                "message" => "Customer Details Received !!",
                "data" => $customerDetails
            ], 200);



        } catch (ModelNotFoundExeption $e) {
            return response()->json(["error" => "Not Item Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);

        }
    }


    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'party_name' => 'required|string|max:255|unique:customers,party_name',
                'pan_number' => 'nullable|string|unique:customers,pan_number',
                'billing_address' => 'nullable|string',
                'opening_balance' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'ledger_type' => 'nullable|in:customer,vendor,both',
                'address' => 'nullable|string',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|unique:customers,email|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_phone' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255',
                'ward_no' => 'nullable|string|max:255',
                'area' => 'nullable|string|max:100',
                'bank_name' => 'nullable|string|max:255',
                'bank_account_number' => 'nullable|string|max:255',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
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



    public function show($id): JsonResponse
    {
        try {
            $item = Customer::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
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
                'billing_address' => 'nullable|string',
                'ledger_type' => 'nullable|in:customer,vendor,both',
                'address' => 'nullable|string',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|unique:customers,email,' . $id,
                'contact_person' => 'nullable|string|max:255',
                'contact_person_phone' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255',
                'ward_no' => 'nullable|string|max:255',
                'area' => 'nullable|string|max:100',
                'bank_name' => 'nullable|string|max:255',
                'bank_account_number' => 'nullable|string|max:255',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
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
            \Log::error($e);
            return response()->json(['error' => 'Customer not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
