<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PurchaseMasterKey;
use App\Models\SalesMasterKey;
use App\Models\User;
use App\Stubs\MainGroupStub;
use DB;
use Hash;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class CompanyController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = Company::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

    // Store a new resource
    public function store(Request $request): JsonResponse
    {
        // Check if the user is a super_admin
        $user = Auth::user();
        if (!$user || !$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Super admin required',
            ], 403);
        }

        try {
            // Define validation rules
            $validated = $request->validate([
                // Company fields
                'name' => 'required|string|max:255|unique:companies,name',
                'licence_issue_date' => 'nullable|string|max:255',
                'working_date' => 'nullable|string|max:255',
                'is_vatable' => 'nullable|boolean',
                'reg_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'vat_number' => 'nullable|string|max:255',
                'email_address' => 'nullable|string|email|max:255',
                'website' => 'nullable|string|max:255',
                'fax' => 'nullable|string|max:255',
                'logo' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'palika_name' => 'nullable|string|max:255',
                'ward_number' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_position' => 'nullable|string|max:255',
                'agreement_holder_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'license_number' => 'nullable|string|max:255',
                'activation_key' => 'nullable|string|max:255',
                'url_link' => 'nullable|string|max:255',
                // Admin fields
                'admin_email' => 'required|string|email|max:255|unique:users,email',
                'admin_name' => 'required|string|max:255',
                'password' => 'required|string|min:6|confirmed',
            ]);

            // Start a database transaction
            DB::beginTransaction();

            // Create the Company
            $company = Company::create([
                'name' => $validated['name'],
                'licence_issue_date' => $validated['licence_issue_date'] ?? '',
                'working_date' => $validated['working_date'] ?? '',
                'reg_number' => $validated['reg_number'] ?? '',
                'pan_number' => $validated['pan_number'] ?? '',
                'is_vatable' => $validated['is_vatable'] ?? '',
                'vat_number' => $validated['vat_number'] ?? '',
                'full_address' => $validated['full_address'] ?? '',
                'email_address' => $validated['email_address'] ?? '',
                'website' => $validated['website'] ?? '',
                'fax' => $validated['fax'] ?? '',
                'logo' => $validated['logo'] ?? '',
                'province' => $validated['province'] ?? '',
                'district' => $validated['district'] ?? '',
                'palika_name' => $validated['palika_name'] ?? '',
                'ward_number' => $validated['ward_number'] ?? '',
                'contact_number' => $validated['contact_number'] ?? '',
                'contact_person' => $validated['contact_person'] ?? '',
                'contact_person_position' => $validated['contact_person_position'] ?? '',
                'agreement_holder_name' => $validated['agreement_holder_name'] ?? '',
                'phone' => $validated['phone'] ?? '',
                'position' => $validated['position'] ?? '',
                'license_number' => $validated['license_number'] ?? '',
                'activation_key' => $validated['activation_key'] ?? '',
                'url_link' => $validated['url_link'] ?? '',
            ]);

            // Create the PurchaseMasterKey with default values
            $company->purchaseMasterKey()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'product_code' => false,
                'free' => false,
                'discount_percent' => false,
                'discount_amount' => false,
                'discount' => false,
                'excise_duty' => false,
                'health_insurance' => false,
                'freight_charge' => false,
                'batch_no' => false,
                'discount_after_vat' => false,
                'expiry_date' => false,
                'mfd' => false,
            ]);

            $company->salesMasterKey()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'salesman' => false,
                'product_code' => false,
                'credit_days' => false,
                'balance' => false,
                'store' => false,
                'location' => false,
                'direct_whatsapp_system' => false,
                'bill_type' => false,
                'free' => false,
                'discount' => false,
                'discount_percent' => false,
                'discount_amount' => false,
                'additional' => false,
                'mfd' => false,
                'excise_duty' => false,
                'health_insurance' => false,
                'freight_charge' => false,
                'batch_no' => false,
                'discount_after_vat' => false,
                'expiry_date' => false,
            ]);

            // Create the Company Admin
            $companyAdmin = User::create([
                'email' => $validated['admin_email'],
                'name' => $validated['admin_name'],
                'password' => Hash::make($validated['password']),
            ]);

            MainGroupStub::createMainGroups($company->id);

            // Assign the company_admin role
            $role = Role::firstOrCreate([
                'name' => 'company_admin',
                'guard_name' => 'api'
            ]);
            $companyAdmin->assignRole($role);

            // Link the admin to the company
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $companyAdmin->id
            ]);

            // Commit the transaction
            DB::commit();

            // Eager-load the purchaseMasterKey relationship without global scopes
            $company->load([
                'purchaseMasterKey',
                'salesMasterKey' => function ($query) {
                    $query->withoutGlobalScopes();
                }
            ]);

            MainGroupStub::createMainGroups();

            return response()->json([
                'success' => true,
                'message' => 'Company, admin, and purchase master key created successfully',
                'data' => [
                    'company' => $company,
                    'admin' => $companyAdmin,
                    // 'purchase_master_key' => $purchaseMaster,
                ]
            ], 201);

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Company creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create company, admin, or purchase master key',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


    public function updatePurchaseMasterKey(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            if (!$user || !$user->hasRole('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }

            // Validate request data
            $validator = Validator::make($request->all(), [
                'product_code' => 'nullable|boolean',
                'free' => 'nullable|boolean',
                'discount_percent' => 'nullable|boolean',
                'discount_amount' => 'nullable|boolean',
                'discount' => 'nullable|boolean',
                'excise_duty' => 'nullable|boolean',
                'health_insurance' => 'nullable|boolean',
                'freight_charge' => 'nullable|boolean',
                'discount_after_vat' => 'nullable|boolean',
                'expiry_date' => 'nullable|boolean',
                'batch_no' => 'nullable|boolean',
                'mfd' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();


            return DB::transaction(function () use ($user, $validated) {

                $company = $user->company()->first();
                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No company associated with this user',
                    ], 404);
                }

                // Find the PurchaseMasterKey for the user's company
                $purchaseMaster = PurchaseMasterKey::where('company_id', $company->id)->first();

                if (!$purchaseMaster) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Purchase master key not found for this company',
                    ], 404);
                }

                // Update only provided fields
                $updateData = array_filter($validated, function ($value) {
                    return !is_null($value);
                });

                $purchaseMaster->update($updateData);

                return response()->json([
                    'success' => true,
                    'message' => 'Purchase master key updated successfully',
                    'data' => $purchaseMaster,
                ], 200);
            });

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Purchase master key not found',
            ], 404);
        } catch (QueryException $e) {
            Log::error('Purchase master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Purchase master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }


    public function getPurchaseMasterKey(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            if (!$user || !$user->hasRole('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }
            $company = $user->company()->first();
            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'No company associated with this user',
                ], 404);
            }

            // Find the PurchaseMasterKey for the user's company
            $purchaseMaster = PurchaseMasterKey::where('company_id', $company->company_id)->first();

            if (!$purchaseMaster) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase master key not found for this company',
                ], 404);
            }
            return response()->json([
                'success' => true,

                'data' => $purchaseMaster,
            ], 200);

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Purchase master key not found',
            ], 404);
        } catch (QueryException $e) {
            Log::error('Purchase master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Purchase master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }

    public function updateSaleMasterKey(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            if (!$user || !$user->hasRole('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }

            // Validate request data
            $validator = Validator::make($request->all(), [
                'product_code' => 'nullable|boolean',
                'salesman' => 'nullable|boolean',
                'free' => 'nullable|boolean',
                'discount_percent' => 'nullable|boolean',
                'discount_amount' => 'nullable|boolean',
                'excise_duty' => 'nullable|boolean',
                'health_insurance' => 'nullable|boolean',
                'freight_charge' => 'nullable|boolean',
                'discount_after_vat' => 'nullable|boolean',
                'expiry_date' => 'nullable|boolean',
                'batch_no' => 'nullable|boolean',
                'credit_days' => 'nullable|boolean',
                'balance' => 'nullable|boolean',
                'store' => 'nullable|boolean',
                'location' => 'nullable|boolean',
                'direct_mail_system' => 'nullable|boolean',
                'direct_whatsapp_system' => 'nullable|boolean',
                'bill_type' => 'nullable|boolean',
                'discount' => 'nullable|boolean',
                'additional' => 'nullable|boolean',
                'mfd' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();
            return DB::transaction(function () use ($user, $validated) {

                $company = $user->company()->first();
                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No company associated with this user',
                    ], 404);
                }

                // Find the PurchaseMasterKey for the user's company
                $saleMaster = SalesMasterKey::where('company_id', $company->company_id)->first();

                if (!$saleMaster) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sale master key not found for this company',
                    ], 404);
                }

                // Update only provided fields
                $updateData = array_filter($validated, function ($value) {
                    return !is_null($value);
                });

                $saleMaster->update($updateData);

                return response()->json([
                    'success' => true,
                    'message' => 'Sales master key updated successfully',
                    'data' => $saleMaster,
                ], 200);
            });

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Sale master key not found',
            ], 404);
        } catch (QueryException $e) {
            Log::error('Sale master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        } catch (\Exception $e) {
            Log::error('sale master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }
    public function getSalesMasterKey(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            if (!$user || !$user->hasRole('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }
            $company = $user->company()->first();

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'No company associated with this user',
                ], 404);
            }

            // Find the PurchaseMasterKey for the user's company
            $saleMaster = SalesMasterKey::where('company_id', $company->company_id)->first();

            if (!$saleMaster) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sale master key not found for this company',
                ], 404);
            }
            return response()->json([
                'success' => true,

                'data' => $saleMaster,
            ], 200);

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Purchase master key not found',
            ], 404);
        } catch (QueryException $e) {
            Log::error('Purchase master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Purchase master key update failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }
    /**
     * Update the specified company and its admin user in storage.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole('company_admin') || !$user->tokenCan('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }
            $companyUser = CompanyUser::where('user_id', $user->id)->first();

            if (!$companyUser || !$companyUser->company) {
                return response()->json([
                    'success' => false,
                    'message' => 'No company associated with this user',
                ], 404);
            }

            $company = $companyUser->company;
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255|unique:companies,name,' . $company->id,
                'licence_issue_date' => 'nullable|string|max:255',
                'working_date' => 'nullable|string|max:255',
                'reg_number' => 'nullable|string|max:255',
                'is_vatable' => 'nullable|boolean',
                'pan_number' => 'nullable|string|max:255',
                'vat_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'email_address' => 'nullable|string|email|max:255',
                'website' => 'nullable|string|max:255',
                'fax' => 'nullable|string|max:255',
                'logo' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'palika_name' => 'nullable|string|max:255',
                'ward_number' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_position' => 'nullable|string|max:255',
                'agreement_holder_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'license_number' => 'nullable|string|max:255',
                'activation_key' => 'nullable|string|max:255',
                'url_link' => 'nullable|string|max:255',
                'admin_name' => 'sometimes|required|string|max:255',
                'admin_email' => 'sometimes|required|string|max:255|unique:users,email,' . $user->id,
            ]);


            $company->update($validated);

            $userUpdates = [];
            if ($request->has('admin_name')) {
                $userUpdates['name'] = $validated['admin_name'];
            }
            if ($request->has('admin_email')) {
                $userUpdates['email'] = $validated['admin_email'];
            }
            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company details updated successfully',
                'data' => [
                    'company' => $company->fresh(),
                    'user' => $user->fresh(),
                ],
            ], 200);

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $company = Company::findOrFail($id);

            $companyUser = CompanyUser::where('company_id', $company->id)->first();
            if (!$companyUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company Not found',
                ], 404);
            }
            $userAdmin = $companyUser->user;
            if (!$userAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user associated with this company',
                ], 404);
            }
            return response()->json([
                'success' => true,
                'data' => [
                    'company' => $company,
                    'user' => $userAdmin,
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }

    }
    public function updateCompany(Request $request, $id): JsonResponse
    {
        try {

            $user = Auth::user();
            if (!$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a super admin',
                ], 403);
            }
            $company = Company::findOrFail($id);

            $companyUser = CompanyUser::where('company_id', $company->id)->first();
            if (!$companyUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user associated with this company',
                ], 404);
            }

            $userAdmin = $companyUser->user;
            if (!$userAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user associated with this company',
                ], 404);
            }


            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'licence_issue_date' => 'nullable|string|max:255',
                'working_date' => 'nullable|string|max:255',
                'is_vatable' => 'nullable|boolean',
                'reg_number' => 'nullable|string|max:255',
                'vat_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'email_address' => 'nullable|string|email|max:255',
                'website' => 'nullable|string|max:255',
                'fax' => 'nullable|string|max:255',
                'logo' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'palika_name' => 'nullable|string|max:255',
                'ward_number' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_position' => 'nullable|string|max:255',
                'agreement_holder_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'license_number' => 'nullable|string|max:255',
                'activation_key' => 'nullable|string|max:255',
                'url_link' => 'nullable|string|max:255',
                'admin_name' => 'sometimes|required|string|max:255',
                'admin_email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $userAdmin->id,
                'password' => 'sometimes|required|string|min:6',
            ]);

            $company->update($validated);
            //MainGroupStub::createMainGroups($company->id);

            $userUpdates = [];
            $newToken = null;
            if ($request->has('admin_name')) {
                $userUpdates['name'] = $validated['admin_name'];
            }
            if ($request->has('admin_email')) {
                $userUpdates['email'] = $validated['admin_email'];
            }
            if ($request->has('password')) {
                $userUpdates['password'] = Hash::make($validated['password']);

                $userAdmin->tokens()->where('abilities', '["company_admin"]')->delete();

                $newToken = $userAdmin->createToken('MatraErpToken', ['company_admin'])->plainTextToken;
            }
            if (!empty($userUpdates)) {
                $userAdmin->update($userUpdates);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company and admin details updated successfully',
                'data' => [
                    'company' => $company->fresh(),
                    'user' => $userAdmin->fresh(),
                    'new_token' => $newToken,
                ],
            ], 200);

        } catch (ValidationException $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Update a resource
    /**
     * Update the specified company in storage.
     */
    public function destroy($id): JsonResponse
    {
        $company = Company::find($id);
        if ($company) {
            $company->delete();
            return response()->json(['message' => 'Company deleted!!']);
        } else {
            return response()->json(['message' => 'Company not found'], 404);
        }
    }
}
