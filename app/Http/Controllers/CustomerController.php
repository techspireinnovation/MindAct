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
use Maatwebsite\Excel\Facades\Excel;

use Illuminate\Validation\Rule;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Log;

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

    // public function index(Request $request): JsonResponse
    // {
    //     $query = Customer::query();

    //     if ($request->has('keywords')) {
    //         $query->where('party_name', 'LIKE', '%' . $request->input('keywords') . '%');
    //     }

    //     return response()->json($query->paginate(50));
    // }


public function index(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required',
            'filter_by' => 'nullable|string',
            'search_party_name' => 'nullable|string|max:255',
            'search_phone' => 'nullable|string|max:20',
            'search_email' => 'nullable|string|max:255',
            'search_pan_number' => 'nullable|string|max:255',
            'search_contact_person' => 'nullable|string|max:255',
            'search_ledger_type' => 'nullable|string|in:customer,vendor,both',
            'search_billing_address' => 'nullable|string|max:255',
            'search_customer_field' => 'nullable|string|max:100',
            'search_customer_field_value' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $companyId = $request->company_id;

        $query = Customer::where('company_id', $companyId);

        // Apply filters
        if ($request->has('search_party_name')) {
            $query->where('party_name', 'LIKE', '%' . $request->input('search_party_name') . '%');
        }

        if ($request->has('search_phone')) {
            $query->where('phone', 'LIKE', '%' . $request->input('search_phone') . '%');
        }

        if ($request->has('search_email')) {
            $query->where('email', 'LIKE', '%' . $request->input('search_email') . '%');
        }

        if ($request->has('search_pan_number')) {
            $query->where('pan_number', 'LIKE', '%' . $request->input('search_pan_number') . '%');
        }

        if ($request->has('search_contact_person')) {
            $query->where('contact_person', 'LIKE', '%' . $request->input('search_contact_person') . '%');
        }

        if ($request->has('search_ledger_type')) {
            $query->where('ledger_type', $request->input('search_ledger_type'));
        }

        if ($request->has('search_billing_address')) {
            $query->where('billing_address', 'LIKE', '%' . $request->input('search_billing_address') . '%');
        }

        // Order by latest first
        $query->orderBy('created_at', 'desc');

        $perPage = $request->input('per_page', 50);
        $customers = $query->paginate($perPage);

        // Transform the customers to match your desired format
        $transformedCustomers = $customers->through(function ($customer) {
            return [
                'id' => $customer->id,
                'party_name' => $customer->party_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'pan_number' => $customer->pan_number,
                'billing_address' => $customer->billing_address,
                'contact_person' => $customer->contact_person,
                'contact_person_phone' => $customer->contact_person_phone,
                'ledger_type' => $customer->ledger_type,
                'opening_balance' => $customer->opening_balance,
                'district' => $customer->district,
                'address' => $customer->address,
                'country' => $customer->country,
                'state' => $customer->state,
                'city' => $customer->city,
                'vdc_municipality' => $customer->vdc_municipality,
                'ward_no' => $customer->ward_no,
                'area' => $customer->area,
                'bank_name' => $customer->bank_name,
                'bank_id' => $customer->bank_id,
                'bank_account_number' => $customer->bank_account_number,
                'company_id' => $customer->company_id,
                'is_active' => $customer->is_active,
                'created_at' => $customer->created_at,
                'updated_at' => $customer->updated_at,
                'deleted_at' => $customer->deleted_at,
                'customer_fields' => []
            ];
        });

        return response()->json([
            'data' => $transformedCustomers->items(),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total()
            ]
        ]);

    } catch (QueryException $e) {
       
        return response()->json(['error' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
       
        return response()->json(['error' => 'Unexpected error occurred.'], 500);
    }
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
           
            return response()->json(["error" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
           
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
           
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
           
            return response()->json(["error" => "Item not Found !!"], 404);
        } catch (QueryException $e) {

           
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
           
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
           
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

           
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
           
            return response()->json([
                'error' => 'not_found',
                'message' => 'Customer not found!'
            ], 404);

        } catch (QueryException $e) {
           
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the customer.'
            ], 500);

        } catch (\Exception $e) {
           
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
           
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Unexpected error occurred !!'], 500);
        }
    }



public function importCustomerExcel(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:xlsx,xls,csv',
    ]);

    $companyId = $request->company_id;

    // Read Excel
    $rows = Excel::toArray(null, $request->file('file'));
    
    if (empty($rows) || empty($rows[0])) {
        return response()->json(['message' => 'No data found in Excel file'], 400);
    }

    $data = $rows[0];

    // Skip header row (index 0)
    $dataRows = array_slice($data, 1);

    if (empty($dataRows)) {
        return response()->json(['message' => 'No data rows found after skipping header'], 400);
    }

    // Get existing customers for this company to check duplicates
    $existingCustomers = Customer::where('company_id', $companyId)
        ->get()
        ->map(function ($customer) {
            return [
                'party_name' => strtolower(trim($customer->party_name)),
                'pan_number' => $customer->pan_number ? strtolower(trim($customer->pan_number)) : null,
                'email' => $customer->email ? strtolower(trim($customer->email)) : null,
                'phone' => $customer->phone ? trim($customer->phone) : null
            ];
        })
        ->toArray();

    $successCount = 0;
    $errors = [];
    $skippedCount = 0;

    // First pass: Validate all rows and check for duplicates
    $allRowsData = [];
    $importPhoneNumbers = [];
    $importPanNumbers = [];
    $importEmails = [];

    foreach ($dataRows as $index => $row) {
        $rowNumber = $index + 2;

        // Map Excel columns
        $mappedRow = [
            'ledger_type' => $row[0] ?? null,
            'party_name' => $row[1] ?? null,
            'phone' => $row[2] ?? null,
            'billing_address' => $row[3] ?? null,
            'pan_number' => $row[4] ?? null,
            'email' => $row[5] ?? null,
            'contact_person' => $row[6] ?? null,
            'contact_person_phone' => $row[7] ?? null,
        ];

        if (empty(trim($mappedRow['party_name'] ?? ''))) {
            $errors[] = [
                'row_number' => $rowNumber,
                'errors' => ['Party name is required'],
                'row_data' => $mappedRow
            ];
            continue;
        }

        // Basic validation
        $validator = Validator::make($mappedRow, [
            'ledger_type' => 'required|in:customer,vendor,both',
            'party_name' => 'required|string|max:255',
            'phone' => 'nullable|digits:10',
            'billing_address' => 'nullable|string',
            'pan_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_person_phone' => 'nullable|string|max:20',
        ], [
            'phone.digits' => 'The contact number must be exactly 10 digits.',
            'ledger_type.in' => 'Ledger type must be one of: customer, vendor, both.',
            'email.email' => 'The email address must be a valid email.',
        ]);

        if ($validator->fails()) {
            $errors[] = [
                'row_number' => $rowNumber,
                'errors' => $validator->errors()->all(),
                'row_data' => $mappedRow
            ];
            continue;
        }

        // Check duplicates from DB
        $phone = $mappedRow['phone'] ? trim($mappedRow['phone']) : null;
        $panNumber = $mappedRow['pan_number'] ? strtolower(trim($mappedRow['pan_number'])) : null;
        $email = $mappedRow['email'] ? strtolower(trim($mappedRow['email'])) : null;

        foreach ($existingCustomers as $existingCustomer) {
            if ($phone && $existingCustomer['phone'] === $phone) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'errors' => ['Contact number already exists in database'],
                    'row_data' => $mappedRow
                ];
                continue 2;
            }
            if ($panNumber && $existingCustomer['pan_number'] === $panNumber) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'errors' => ['PAN number already exists in database'],
                    'row_data' => $mappedRow
                ];
                continue 2;
            }
            if ($email && $existingCustomer['email'] === $email) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'errors' => ['Email address already exists in database'],
                    'row_data' => $mappedRow
                ];
                continue 2;
            }
        }

        // Check duplicates inside file
        if ($phone) {
            if (in_array($phone, $importPhoneNumbers)) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'errors' => ['Contact number duplicate within the import file'],
                    'row_data' => $mappedRow
                ];
                continue;
            }
            $importPhoneNumbers[] = $phone;
        }

        if ($panNumber) {
            if (in_array($panNumber, $importPanNumbers)) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'errors' => ['PAN number duplicate within the import file'],
                    'row_data' => $mappedRow
                ];
                continue;
            }
            $importPanNumbers[] = $panNumber;
        }

        if ($email) {
            if (in_array($email, $importEmails)) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'errors' => ['Email address duplicate within the import file'],
                    'row_data' => $mappedRow
                ];
                continue;
            }
            $importEmails[] = $email;
        }

        $allRowsData[] = [
            'row_number' => $rowNumber,
            'data' => $mappedRow
        ];
    }

    if (!empty($errors)) {
        return response()->json([
            'message' => 'Import failed due to validation errors. Please fix the errors and try again.',
            'error_count' => count($errors),
            'errors' => $errors
        ], 422);
    }

    // Second pass: Import valid rows
    DB::beginTransaction();
    try {
        foreach ($allRowsData as $rowItem) {
            $mappedRow = $rowItem['data'];
            $rowNumber = $rowItem['row_number'];

            try {
                $customerData = [
                    'company_id' => $companyId,
                    'ledger_type' => $mappedRow['ledger_type'] ?? 'customer',
                    'party_name' => trim($mappedRow['party_name']),
                    'phone' => $mappedRow['phone'] ?? null,
                    'billing_address' => $mappedRow['billing_address'] ?? null,
                    'pan_number' => $mappedRow['pan_number'] ?? null,
                    'email' => $mappedRow['email'] ?? null,
                    'contact_person' => $mappedRow['contact_person'] ?? null,
                    'contact_person_phone' => $mappedRow['contact_person_phone'] ?? null,
                    'is_active' => true,
                ];

                Customer::create($customerData);
                $successCount++;

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Import failed due to database error at row ' . $rowNumber,
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        DB::commit();

        return response()->json([
            'message' => 'Customer import completed successfully!',
            'inserted_count' => $successCount
        ]);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Import failed due to transaction error',
            'error' => $e->getMessage()
        ], 500);
    }
}


}
