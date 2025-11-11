<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

use Illuminate\Validation\Rule;

use Illuminate\Support\Facades\Validator;


class CustomerController extends Controller
{



    public function getCustomerBalance($customer_id)
    {
        $customer = Customer::where('id', $customer_id)->first();

        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $balance = (float) $customer->opening_balance;

        $purchaseCredits = Purchase::where('customer_id', $customer_id)
            ->whereNull('deleted_at')
            ->get()
            ->sum(function ($purchase) {
                return (float) ($purchase->payment['credit'] ?? 0);
            });
        $balance -= $purchaseCredits;

        $purchaseReturnCredits = PurchaseReturn::where('customer_id', $customer_id)
            ->whereNull('deleted_at')
            ->get()
            ->sum(function ($purchaseReturn) {
                return (float) ($purchaseReturn->payment['credit'] ?? 0);
            });
        $balance += $purchaseReturnCredits;

        $saleCredits = Sale::where('customer_id', $customer_id)
            ->whereNull('deleted_at')
            ->get()
            ->sum(function ($sale) {
                return (float) ($sale->payment['credit'] ?? 0);
            });
        $balance += $saleCredits;

        $saleReturnCredits = SalesReturn::where('customer_id', $customer_id)
            ->whereNull('deleted_at')
            ->get()
            ->sum(function ($saleReturn) {
                return (float) ($saleReturn->payment['credit'] ?? 0);
            });
        $balance -= $saleReturnCredits;

        return response()->json([

            'actual_balance' => round($balance, 5)
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->has('keywords')) {
            $query->where('party_name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }


    public function customerList(Request $request)
    {
        try {

            $type = $request->input('type') ?? null;


            if ($type == 'purchase') {


                $customer = Customer::where('company_id', $request->company_id)
                    ->whereNull('deleted_at')
                    ->where('is_active', 1)
                    ->whereIn('ledger_type', ['vendor', 'both'])
                    ->get(['id', 'party_name'])
                    ->map(fn($c) => ['id' => $c->id, 'name' => $c->party_name])
                    ->values();
            } elseif ($type == 'sales') {
                $customer = Customer::where('company_id', $request->company_id)
                    ->whereNull('deleted_at')
                    ->where('is_active', 1)
                    ->whereIn('ledger_type', ['customer', 'both'])
                    ->get(['id', 'party_name'])
                    ->map(fn($c) => ['id' => $c->id, 'name' => $c->party_name])
                    ->values();

            } else {
                $customer = Customer::where('company_id', $request->company_id)
                    ->whereNull('deleted_at')
                    ->where('is_active', 1)
                    ->get(['id', 'party_name'])
                    ->map(fn($c) => ['id' => $c->id, 'name' => $c->party_name])
                    ->values();

            }

            return response()->json([
                "message" => "Customer List Received !!",
                "data" => $customer
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function searchCustomerList(Request $request)
    {
        try {
            $customer_name = $request->input('customer_name');

            $applyFilters = function ($query) use ($customer_name) {
                if ($customer_name) {
                    $query->where('party_name', 'LIKE', "%$customer_name%");
                }
            };

            $customers = Customer::where('company_id', $request->company_id)
                ->whereNull('deleted_at')->tap($applyFilters)
                ->select('party_name', 'id')->get();
            return response()->json([
                "message" => "Customer List Received !!",
                "data" => $customers
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Item not Found !!"], 404);
        } catch (QueryException $e) {

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



        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);

        }
    }


    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required',
                'party_name' => [
                    'required',
                    'string',
                    'max:255',
                    // Rule::unique('customers')

                    //     ->where(function ($query) use ($request) {
                    //         return $query->where('company_id', $request->input('company_id', $request->company_id))
                    //             ->whereNull('deleted_at');
                    //     }),
                ],
                'pan_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('customers')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'billing_address' => 'nullable|string',
                'opening_balance' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'ledger_type' => 'nullable|in:customer,vendor,both',
                'address' => 'nullable|string',
                'phone' => 'nullable|digits:10',
                'email' => [
                    'nullable',
                    'email',
                    'string',
                    'max:255',
                    Rule::unique('customers')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'contact_person' => 'nullable|string|max:255',
                'contact_person_phone' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255',
                'ward_no' => 'nullable|string|max:255',
                'area' => 'nullable|string|max:100',
                'bank_name' => 'nullable|string|max:255',
                'bank_id' => 'nullable|numeric',
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
            DB::commit();

            return response()->json([
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);

        } catch (QueryException $e) {
            DB::rollBack();
            \Log::error($e);

          

            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            DB::rollBack();


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
                'company_id' => 'required',
                'party_name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('customers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'pan_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('customers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'billing_address' => 'nullable|string',
                'ledger_type' => 'nullable|in:customer,vendor,both',
                'address' => 'nullable|string',
                'phone' => 'nullable|digits:10',
                'email' => [
                    'nullable',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('customers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'contact_person' => 'nullable|string|max:255',
                'contact_person_phone' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255',
                'ward_no' => 'nullable|string|max:255',
                'area' => 'nullable|string|max:100',
                'bank_name' => 'nullable|string|max:255',
                'bank_id' => 'nullable|numeric',
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
            $customer = Customer::findOrFail($id);

            $usedIn = [];

            if ($customer->purchasesUse()->exists()) {
                $usedIn[] = 'purchases';
            }

            if ($customer->salesUse()->exists()) {
                $usedIn[] = 'sales';
            }

            if ($customer->purchaseProductsUse()->exists()) {
                $usedIn[] = 'purchase_products';
            }

            if ($customer->paymentVoucherDetails()->exists()) {
                $usedIn[] = 'payment_voucher_details';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Customer cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);
            }

            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'not_found',
                'message' => 'Customer not found!'
            ], 404);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the customer.'
            ], 500);

        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the customer.'
            ], 500);
        }
    }



    public function activeCustomers(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id;

            if (!$companyId) {
                return response()->json([
                    'message' => 'No Associated company Found !!'
                ], 404);
            }

            $customers = Customer::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get(['id', 'party_name'])
                ->map(fn($customer) => [
                    'id' => $customer->id,
                    'name' => $customer->party_name,
                ])
                ->values()
                ->toArray();

            if (empty($customers)) {
                return response()->json([
                    'message' => 'No active customers found !!',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'Active customers received successfully',
                'data' => $customers
            ], 200);

        } catch (QueryException $e) {
            \Log::error('DB Error in activeCustomers: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in activeCustomers: ' . $e->getMessage());
            return response()->json(['error' => 'Unexpected error occurred !!'], 500);
        }
    }

}
