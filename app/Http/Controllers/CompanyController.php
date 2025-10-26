<?php

namespace App\Http\Controllers;


use Illuminate\Support\Facades\Artisan;
use App\Providers\TenancyServiceProvider;
use App\Jobs\InitializeTenant;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\ProductType;
use App\Models\MeasureUnit;
use App\Models\PurchaseMasterKey;
use App\Models\SalesMasterKey;
use App\Models\User;
use App\Stubs\MainGroupStub;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\JobPipeline\JobPipeline;
use DB;
use Hash;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use App\Models\Tenant;

use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Features\TenantDatabase;
use Str;

use App\Providers\TenantInitializer;

use Stancl\Tenancy\Database\DatabaseManager;

class CompanyController extends Controller
{



    protected $tenantInitializer;

    public function __construct(TenantInitializer $tenantInitializer)
    {
        $this->tenantInitializer = $tenantInitializer; // Use the injected instance
    }


    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user || !$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Super admin required',
            ], 403);
        }

        // Set higher execution time limit
        set_time_limit(300); // 5 minutes

        $company = null;
        $tenant = null;
        $companyAdmin = null;
        $databaseName = null;

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'licence_issue_date' => 'nullable|date_format:Y-m-d',
                'working_date' => 'nullable|date_format:Y-m-d',
                'is_vatable' => 'nullable|boolean',
                'reg_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
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
                'admin_selection' => 'required|in:existing,new',
                'existing_admin_id' => 'nullable|exists:users,id,deleted_at,NULL',
                'admin_email' => 'sometimes|nullable|string|email|max:255|unique:users,email,NULL,id,deleted_at,NULL|required_if:admin_selection,new',
                'admin_name' => 'sometimes|nullable|string|max:255|required_if:admin_selection,new',
                'password' => 'sometimes|nullable|string|min:6|required_if:admin_selection,new|confirmed',
            ]);

            // 1️⃣ Create company in CENTRAL database
            $company = Company::create([
                'name' => $validated['name'],
                'licence_issue_date' => $validated['licence_issue_date'] ?? null,
                'working_date' => $validated['working_date'] ?? null,
                'reg_number' => $validated['reg_number'] ?? '',
                'pan_number' => $validated['pan_number'] ?? '',
                'is_vatable' => $validated['is_vatable'] ?? false,
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
            Log::info('Company created successfully', ['company_id' => $company->id]);

            $sluggedName = Str::slug($company->name);

            // Generate base database name
            $baseDatabaseName = $sluggedName . '_' . $company->id;
            $databaseName = $baseDatabaseName;
            $counter = 1;

            // Ensure unique database name
            while (Tenant::where('database', $databaseName)->exists()) {
                $databaseName = $baseDatabaseName . '_' . $counter++;
            }


            // Base tenancy slug
            $baseSlug = 'tenant_company_' . Str::slug($company->name);
            $tenancySlug = $baseSlug;
            $slugCounter = 1;

            // Ensure unique tenancy slug
            while (Tenant::where('data->tenancy_slug', $tenancySlug)->exists()) {
                $tenancySlug = $baseSlug . '_' . $slugCounter;
                $slugCounter++;
            }
            $tenant = Tenant::create([
                'id' => (string) Str::uuid(),
                'database' => $databaseName,
                'company_id' => $company->id,
                'tenancy_slug' => $tenancySlug,
            ]);

            // $tenant->data = [
            //     'company_id' => $company->id,
            //     'company_name' => $company->name,
            //     'tenancy_slug' => $tenancySlug,
            // ];
            // $tenant->save();





            Log::info('Tenant created successfully', ['tenant_id' => $tenant->id]);

            // 3️⃣ Initialize tenant (drop, create, and migrate)
            $this->tenantInitializer->initializeTenant($tenant, $databaseName);

            // 4️⃣ Create domain if provided
            if (!empty($validated['url_link'])) {
                Domain::create([
                    'tenant_id' => $tenant->id,
                    'domain' => $validated['url_link'],
                ]);
                Log::info('Domain created successfully', ['tenant_id' => $tenant->id]);
            }

            // 5️⃣ Insert tenant-specific data
            $tenant->run(function () use ($validated, $company, $tenant) {
                Branch::create([
                    'name' => $validated['name'],
                    'company_id' => $company->id,
                    'branch_type' => 'Main',
                    'is_active' => true,
                    'is_primary' => true,
                ]);

                PurchaseMasterKey::create(['company_id' => $company->id]);
                SalesMasterKey::create(['company_id' => $company->id]);

                ProductType::insert([
                    ['name' => 'Inventory', 'delete_status' => 0, 'company_id' => $company->id],
                    ['name' => 'Assets', 'delete_status' => 0, 'company_id' => $company->id],
                    ['name' => 'Service', 'delete_status' => 0, 'company_id' => $company->id],
                    ['name' => 'Raw Materials', 'delete_status' => 0, 'company_id' => $company->id],
                ]);

                MeasureUnit::create([
                    'name' => 'Piece',
                    'symbol' => 'Pcs',
                    'quantity' => 1,
                    'company_id' => $company->id,
                ]);

                Role::firstOrCreate([
                    'name' => 'company_admin',
                    'guard_name' => 'api',
                ]);

                Log::info('Tenant data setup completed', ['tenant_id' => $tenant->id]);
            });

            // 6️⃣ Handle admin (in central database)
            $companyAdmin = $validated['admin_selection'] === 'existing'
                ? User::findOrFail($validated['existing_admin_id'])
                : User::withTrashed()->firstOrCreate(
                    ['email' => $validated['admin_email']],
                    [
                        'name' => $validated['admin_name'],
                        'password' => Hash::make($validated['password']),
                    ]
                );

            if ($companyAdmin->trashed()) {
                $companyAdmin->restore();
                Log::info('Restored trashed admin', ['admin_id' => $companyAdmin->id]);
            }

            $role = Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'api']);
            if (!$companyAdmin->hasRole('company_admin')) {
                $companyAdmin->assignRole($role);
                Log::info('Assigned company_admin role', ['admin_id' => $companyAdmin->id]);
            }

            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $companyAdmin->id,
            ]);

            Log::info('Company and tenant setup complete', [
                'company_id' => $company->id,
                'tenant_id' => $tenant->id,
                'admin_id' => $companyAdmin->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company, tenant database, branch, and admin setup successfully',
                'data' => [
                    'company' => $company,
                    'admin' => $companyAdmin,
                ],
            ], 201);

        } catch (ValidationException $e) {
            Log::error('Validation error during company creation', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Clean up tenant database
            if ($databaseName) {
                $this->tenantInitializer->cleanupTenant($databaseName);
            }

            // Delete tenant and company records
            if ($tenant) {
                $tenant->delete();
            }
            if ($company) {
                $company->delete();
            }

            Log::error('Failed to create company or tenant', [
                'error' => $e->getMessage(),
                'tenant_id' => isset($tenant) ? $tenant->id : null,
                'company_id' => isset($company) ? $company->id : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create company or tenant',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    public function index(Request $request): JsonResponse
    {
        $query = Company::query();

        if ($request->has('keywords')) {
            $keywords = $request->input('keywords');
            $query->where(function ($q) use ($keywords) {
                $q->where('name', 'LIKE', '%' . $keywords . '%')
                    ->orWhere('full_address', 'LIKE', '%' . $keywords . '%')
                    ->orWhere('email_address', 'LIKE', '%' . $keywords . '%')
                    ->orWhere('phone', 'LIKE', '%' . $keywords . '%');
            });
        }

        $companies = $query->orderBy('id', 'asc')->paginate(50);

        $transformed = $companies->getCollection()->map(function ($company) {
            return $company->toArray(); // get all fields automatically
        });

        $companies->setCollection($transformed);

        return response()->json($companies);
    }

    public function companyList(Request $request): JsonResponse
    {
        // Check if the user is a super_admin
        $user = Auth::user();
        if (!$user || !$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Super admin required',
            ], 200);
        }

        try {
            $companies = Company::whereNull('deleted_at')
                ->get(['id', 'name'])
                ->map(fn($company) => ['id' => $company->id, 'name' => $company->name])
                ->values()
                ->toArray();

            if (empty($companies)) {
                \Log::info('No companies found in companyList', ['user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'No companies found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company list retrieved successfully',
                'data' => $companies
            ], 200);
        } catch (QueryException $e) {
            \Log::error('Database error in companyList: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Database error occurred',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in companyList: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }

    public function companyDetails(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Super admin required',
            ], 200);
        }

        try {
            $companyName = $request->input('name');

            if (!$companyName) {
                \Log::info('Company name not provided in companyDetails', ['user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Company name is required',
                ], 400);
            }

            $query = Company::where('name', $companyName)
                ->whereNull('deleted_at');
            \Log::info('companyDetails query', [
                'name' => $companyName,
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings()
            ]);

            $company = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Company details retrieved successfully',
                'data' => $company
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::info('Company not found in companyDetails', [
                'name' => $companyName,
                'user_id' => $user->id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        } catch (QueryException $e) {
            \Log::error('Database error in companyDetails: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Database error occurred',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in companyDetails: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }

    public function companyBranchList(Request $request): JsonResponse
    {
        // Check if the user is a super_admin
        $user = Auth::user();
        if (!$user || !$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Super admin required',
            ], 200);
        }

        try {
            // Validate input parameters
            $companyId = $request->input('id');
            $companyName = $request->input('name');

            if (!$companyId && !$companyName) {
                \Log::info('No parameters provided in companyBranchList', ['user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Either company ID or name is required',
                ], 400);
            }

            \Log::info('companyBranchList called', [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'company_name' => $companyName
            ]);

            // Build query based on provided parameter
            $query = Company::whereNull('deleted_at')
                ->with([
                    'branches' => function ($query) {
                        $query->whereNull('deleted_at')
                            ->where('is_active', 1)
                            ->select('id', 'name', 'company_id');
                    }
                ])
                ->select('id', 'name');

            if ($companyId) {
                $query->where('id', $companyId);
            } elseif ($companyName) {
                $query->where('name', $companyName);
            }

            \Log::info('companyBranchList query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings()
            ]);

            $company = $query->firstOrFail();

            // Check if company has active branches
            if ($company->branches->isEmpty()) {
                \Log::info('No active branches found for company in companyBranchList', [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'No active branches found for the specified company',
                ], 404);
            }

            // Format response
            $result = [
                'id' => $company->id,
                'name' => $company->name,
                'branches' => $company->branches->map(function ($branch) {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name
                    ];
                })->values()->toArray()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Company and branch details retrieved successfully',
                'data' => $result
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::info('Company not found in companyBranchList', [
                'id' => $companyId,
                'name' => $companyName,
                'user_id' => $user->id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Company not found with provided ID or name',
            ], 404);
        } catch (QueryException $e) {
            \Log::error('Database error in companyBranchList: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Database error occurred',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in companyBranchList: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }

    // Store a new resource

    public function updatePurchaseMasterKey(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }

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

            return DB::transaction(function () use ($user, $validated, $request) {
                $companyId = $request->company_id;
                if (!$companyId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No company ID provided',
                    ], 403);
                }

                $company = Company::where('id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Company not found or deleted',
                    ], 404);
                }

                $companyUser = CompanyUser::where('user_id', $user->id)
                    ->where('company_id', $companyId)
                    ->first();

                if (!$companyUser) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not associated with this company',
                    ], 403);
                }

                $purchaseMaster = PurchaseMasterKey::where('company_id', $companyId)->first();

                if (!$purchaseMaster) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Purchase master key not found for this company',
                    ], 404);
                }

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
            dd($e->getMessage());
            Log::error('Purchase master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Database error occurred',
            ], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
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
            $user = $request->user();
            \Log::info('getPurchaseMasterKey: User', ['user_id' => $user ? $user->id : null]);
            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: User lacks required role',
                ], 200);
            }

            $companyId = $request->company_id;
            \Log::info('getPurchaseMasterKey: Company ID', ['company_id' => $companyId]);
            if (!$companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No company ID provided',
                ], 400);
            }

            $company = \App\Models\Company::where('id', $companyId)
                ->whereNull('deleted_at')
                ->first();
            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found or deleted',
                ], 404);
            }

            $companyUser = \App\Models\CompanyUser::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->first();
            if (!$companyUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with this company',
                ], 200);
            }

            $purchaseMaster = \App\Models\PurchaseMasterKey::where('company_id', $companyId)->first();
            \Log::info('getPurchaseMasterKey: PurchaseMasterKey', ['found' => $purchaseMaster ? true : false]);
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
        } catch (QueryException $e) {
            \Log::error('getPurchaseMasterKey QueryException', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('getPurchaseMasterKey Exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }



    public function updateSaleMasterKey(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }

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

            return DB::transaction(function () use ($user, $validated, $request) {
                $companyId = $request->company_id;
                if (!$companyId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No company ID provided',
                    ], 403);
                }

                // Verify company exists and is not soft-deleted
                $company = Company::where('id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Company not found or deleted',
                    ], 404);
                }

                $companyUser = CompanyUser::where('user_id', $user->id)
                    ->where('company_id', $companyId)
                    ->first();

                if (!$companyUser) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not associated with this company',
                    ], 403);
                }

                // Find the SalesMasterKey for the company
                $saleMaster = SalesMasterKey::where('company_id', $companyId)->first();

                if (!$saleMaster) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sales master key not found for this company',
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
                'message' => 'Sales master key not found',
            ], 404);
        } catch (QueryException $e) {
            Log::error('Sales master key update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Database error occurred',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Sales master key update failed: ' . $e->getMessage());
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
            \Log::info('getSalesMasterKey: User', ['user_id' => $user ? $user->id : null]);
            if (!$user || !$user->hasRole('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 200);
            }

            // Get company_id from middleware
            $companyId = $request->company_id;
            \Log::info('getSalesMasterKey: Company ID', ['company_id' => $companyId]);
            if (!$companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No company ID provided',
                ], 400);
            }

            // Verify company exists and is not soft-deleted
            $company = \App\Models\Company::where('id', $companyId)
                ->whereNull('deleted_at')
                ->first();
            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found or deleted',
                ], 404);
            }

            // Verify user is associated with the company
            $companyUser = \App\Models\CompanyUser::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->first();
            if (!$companyUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with this company',
                ], 200);
            }

            // Find the SalesMasterKey for the company
            $saleMaster = \App\Models\SalesMasterKey::where('company_id', $companyId)->first();
            \Log::info('getSalesMasterKey: SalesMasterKey', ['found' => $saleMaster ? true : false]);
            if (!$saleMaster) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sales master key not found for this company',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $saleMaster,
            ], 200);
        } catch (QueryException $e) {
            \Log::error('getSalesMasterKey QueryException', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('getSalesMasterKey Exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
            ], 500);
        }
    }


    public function update(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (
                !$user || (!$user->hasRole('super_admin') && !$user->hasRole('company_admin')) ||
                !($user->tokenCan('super_admin') || $user->tokenCan('company_admin'))
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Super admin or company admin required',
                ], 200);
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
                'name' => 'sometimes|required|string|max:255|unique:companies,name,' . $company->id . ',id,deleted_at,NULL',
                'licence_issue_date' => 'nullable|date_format:Y-m-d', // Enforce Y-m-d or null
                'working_date' => 'nullable|date_format:Y-m-d', // Enforce Y-m-d or null
                'is_vatable' => 'nullable|boolean',
                'reg_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                // 'vat_number' => 'nullable|string|max:255',
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
                // Admin fields
                'admin_selection' => 'sometimes|required|in:existing,new',
                'existing_admin_id' => 'required_if:admin_selection,existing|exists:users,id,deleted_at,NULL',
                'admin_email' => 'sometimes|nullable|string|email|max:255|unique:users,email,' . ($company->users->first()->id ?? 'NULL') . ',id,deleted_at,NULL|required_if:admin_selection,new',
                'admin_name' => 'sometimes|nullable|string|max:255|required_if:admin_selection,new',
                'password' => 'sometimes|nullable|string|min:6|required_if:admin_selection,new|confirmed',
            ]);

            DB::beginTransaction();

            // Update company details
            $company->update([
                'name' => $validated['name'] ?? $company->name,
                'licence_issue_date' => $validated['licence_issue_date'] ?? $company->licence_issue_date,
                'working_date' => $validated['working_date'] ?? $company->working_date,
                'reg_number' => $validated['reg_number'] ?? $company->reg_number,
                'pan_number' => $validated['pan_number'] ?? $company->pan_number,
                'is_vatable' => $validated['is_vatable'] ?? $company->is_vatable,
                // 'vat_number' => $validated['vat_number'] ?? $company->vat_number,
                'full_address' => $validated['full_address'] ?? $company->full_address,
                'email_address' => $validated['email_address'] ?? $company->email_address,
                'website' => $validated['website'] ?? $company->website,
                'fax' => $validated['fax'] ?? $company->fax,
                'logo' => $validated['logo'] ?? $company->logo,
                'province' => $validated['province'] ?? $company->province,
                'district' => $validated['district'] ?? $company->district,
                'palika_name' => $validated['palika_name'] ?? $company->palika_name,
                'ward_number' => $validated['ward_number'] ?? $company->ward_number,
                'contact_number' => $validated['contact_number'] ?? $company->contact_number,
                'contact_person' => $validated['contact_person'] ?? $company->contact_person,
                'contact_person_position' => $validated['contact_person_position'] ?? $company->contact_person_position,
                'agreement_holder_name' => $validated['agreement_holder_name'] ?? $company->agreement_holder_name,
                'phone' => $validated['phone'] ?? $company->phone,
                'position' => $validated['position'] ?? $company->position,
                'license_number' => $validated['license_number'] ?? $company->license_number,
                'activation_key' => $validated['activation_key'] ?? $company->activation_key,
                'url_link' => $validated['url_link'] ?? $company->url_link,
            ]);

            // Handle admin updates
            if (isset($validated['admin_selection'])) {
                $branch = $company->branches()->where('is_primary', true)->first();
                if (!$branch) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'No primary branch found for the company',
                    ], 404);
                }

                if ($validated['admin_selection'] === 'existing') {
                    $companyAdmin = User::find($validated['existing_admin_id']);
                    if (!$companyAdmin) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Existing admin not found',
                        ], 404);
                    }

                    $role = Role::firstOrCreate([
                        'name' => 'company_admin',
                        'guard_name' => 'api'
                    ]);
                    if (!$companyAdmin->hasRole('company_admin')) {
                        $companyAdmin->assignRole($role);
                    }

                    CompanyUser::where('company_id', $company->id)->delete();

                    CompanyUser::create([
                        'company_id' => $company->id,
                        'user_id' => $companyAdmin->id
                    ]);

                    $companyAdmin->branches()->sync([$branch->id]);
                } else {
                    $companyAdmin = User::withTrashed()->where('email', $validated['admin_email'])->first();

                    if ($companyAdmin && $companyAdmin->trashed()) {
                        $companyAdmin->restore();
                        $companyAdmin->update([
                            'name' => $validated['admin_name'] ?? $companyAdmin->name,
                            'password' => isset($validated['password']) ? Hash::make($validated['password']) : $companyAdmin->password,
                        ]);
                    } else {
                        $companyAdmin = User::create([
                            'email' => $validated['admin_email'],
                            'name' => $validated['admin_name'],
                            'password' => Hash::make($validated['password']),
                        ]);
                    }

                    $role = Role::firstOrCreate([
                        'name' => 'company_admin',
                        'guard_name' => 'api'
                    ]);
                    if (!$companyAdmin->hasRole('company_admin')) {
                        $companyAdmin->assignRole($role);
                    }

                    CompanyUser::where('company_id', $company->id)->delete();

                    CompanyUser::create([
                        'company_id' => $company->id,
                        'user_id' => $companyAdmin->id
                    ]);

                    $companyAdmin->branches()->sync([$branch->id]);
                }
            } else {
                $companyAdmin = $user; // Default to current user if no admin selection
            }

            DB::commit();

            $company->load([
                'purchaseMasterKey',
                'salesMasterKey' => function ($query) {
                    $query->withoutGlobalScopes();
                },
                'branches'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company and admin details updated successfully',
                'data' => [
                    'company' => $company,
                    'admin' => $companyAdmin,
                    'branch' => $company->branches()->where('is_primary', true)->first(),
                ]
            ], 200);

        } catch (ValidationException $e) {
            Log::error('Validation error during company update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Company update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company or admin details',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }



    // public function show($id)
    // {
    //     try {
    //         $company = Company::findOrFail($id);

    //         $companyUser = CompanyUser::where('company_id', $company->id)->first();
    //         if (!$companyUser) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Company Not found',
    //             ], 404);
    //         }
    //         $userAdmin = $companyUser->user;
    //         if (!$userAdmin) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No user associated with this company',
    //             ], 404);
    //         }
    //         return response()->json([
    //             'success' => true,
    //             'data' => [
    //                 'company' => $company,
    //                 'user' => $userAdmin,
    //             ]
    //         ], 200);
    //     } catch (ModelNotFoundException $e) {
    //         \Log::error($e);
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Company not found',
    //         ], 404);
    //     } catch (QueryException $e) {
    //         \Log::error($e);
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'An unexpected error occurred',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     } catch (\Exception $e) {
    //         \Log::error($e);
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'An unexpected error occurred',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }

    // }

    public function show($id): JsonResponse
    {
        try {
            $company = Company::with(['branches'])->findOrFail($id);

            // Get the first associated admin via pivot
            $companyUser = CompanyUser::where('company_id', $company->id)->with('user')->first();
            $admin = $companyUser->user ?? null;

            $admin_selection = $admin ? 'existing' : 'new';
            $existing_admin_id = $admin ? $admin->id : null;

            return response()->json([
                'success' => true,
                'message' => 'Company details retrieved successfully',
                'data' => [
                    'company' => $company,
                    'admin_selection' => $admin_selection,
                    'existing_admin_id' => $existing_admin_id,
                    'admin' => $admin,
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Exception in show: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred'
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
                ], 200);
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
                // 'vat_number' => 'nullable|string|max:255',
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
                'admin_selection' => 'required|in:existing,new',

                'admin_email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $userAdmin->id,
                'password' => 'sometimes|required|string|min:6',
            ]);

            $company->update($validated);
            MainGroupStub::createMainGroups($company->id);

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
        try {
            $company = Company::find($id);

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found'
                ], 404);
            }

            DB::beginTransaction();

            $companyUserIds = CompanyUser::where('company_id', $company->id)->pluck('user_id');

            CompanyUser::where('company_id', $company->id)->delete();

            foreach ($companyUserIds as $userId) {
                $remainingCompanies = CompanyUser::where('user_id', $userId)->count();
                if ($remainingCompanies === 0) {
                    User::where('id', $userId)->delete();
                }
            }

            $branchIds = Branch::where('company_id', $company->id)->pluck('id');

            DB::table('branch_user')->whereIn('branch_id', $branchIds)->delete();

            Branch::where('company_id', $company->id)->delete();

            $company->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company, associated records, and exclusive users deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Company deletion failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }




}
