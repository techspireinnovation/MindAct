<?php

namespace App\Http\Controllers;
use Stancl\Tenancy\Facades\Tenancy;

use App\Models\CompanyUser;
use App\Models\Company;
use App\Providers\TenancyServiceProvider;
use App\Models\Tenant;
use App\Models\User;
use Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Validator;
use App\Models\Branch;

class CompanyAdminController extends Controller
{




    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json(['success' => false, 'message' => 'Invalid credentials'], 200);
            }

            $user = Auth::user();

            $allowedRoles = ['company_admin', 'company_user', 'master_user'];
            if (!$user->hasAnyRole($allowedRoles)) {
                Auth::logout();
                return response()->json(['success' => false, 'message' => 'Not authorised for company access'], 200);
            }

            $role = $user->getRoleNames()
                ->intersect($allowedRoles)
                ->first();

            $tempToken = $user->createToken(
                'TempToken',
                ['company_access'],
                now()->addMinutes(30)
            )->plainTextToken;

            return response()->json([
                'success' => true,
                'role' => $role,
                'message' => 'Please choose a company-admin to impersonate.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'token' => $tempToken,
                ],
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while logging in',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function selectAdmin(Request $request)
    {
        try {


            $user = Auth::guard('api')->user();

            if (!$user || !$user->hasRole('master_user')) {
                return response()->json(['success' => false, 'message' => 'Unauthorised'], 200);
            }

            $request->validate([
                'admin_id' => 'required|exists:users,id',
            ]);

            $valid = CompanyUser::where('user_id', $request->admin_id)
                ->whereIn('company_id', $user->companies()->pluck('companies.id'))
                ->exists();

            if (!$valid) {
                return response()->json(['success' => false, 'message' => 'Invalid admin selection'], 422);
            }

            $companies = CompanyUser::where('user_id', $request->admin_id)
                ->with('company:id,name,is_vatable')
                ->get()
                ->pluck('company');

            $branches = Branch::whereIn('company_id', $companies->pluck('id'))
                ->where('is_active', true)
                ->select('id', 'name', 'company_id')
                ->get();

            return response()->json([
                'success' => true,
                'step' => 'choose_company_branch',
                'message' => 'Now choose a company and branch.',
                'data' => [
                    'admin_id' => $request->admin_id,
                    // 'companies' => $companies,
                    // 'branches'  => $branches,
                ],
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while selecting admin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function selectCompany(Request $request)
    {


        try {
            $user = Auth::guard('api')->user();

            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Company access required',
                ], 200);
            }

           
            $rules = [
                'company_id' => 'required|exists:companies,id,deleted_at,NULL',
                'branch_id' => 'required|integer', 
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

         
            $companyUser = CompanyUser::where('user_id', $user->id)
                ->where('company_id', $request->company_id)
                ->first();

            if (!$companyUser) {

                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with the selected company',
                ], 200);
            }

         
            $tenant = Tenant::where('data->company_id', $request->company_id)->first();
            if (!$tenant) {

                return response()->json([
                    'success' => false,
                    'message' => 'Tenant database not found for this company',
                ], 404);
            }

            
            \App\Providers\TenantInitializer::switchTenant($tenant);



           
            $branch = Branch::on('tenant')
                ->where('id', $request->branch_id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();

            if (!$branch) {

                return response()->json([
                    'success' => false,
                    'message' => 'Selected branch is invalid or not associated with the company',
                ], 200);
            }


           
            if ($user->hasRole('company_user')) {
                $userBranch = $user->branches()
                    ->where('branches.id', $request->branch_id)
                    ->where('branches.company_id', $request->company_id)
                    ->whereNull('branches.deleted_at')
                    ->where('branches.is_active', true)
                    ->first();

                if (!$userBranch) {

                    return response()->json([
                        'success' => false,
                        'message' => 'User is not associated with the selected branch',
                    ], 200);
                }
            }

           
            $company = Company::where('id', $request->company_id)
                ->select('id', 'name', 'is_vatable')
                ->first();

            if (!$company) {

                return response()->json([
                    'success' => false,
                    'message' => 'Selected company not found',
                ], 404);
            }

           
            $user->tokens()->delete();
            $abilities = [
                $user->hasRole('company_admin') ? 'company_admin' : ($user->hasRole('master_user') ? 'master_user' : 'company_user'),
                "company:{$request->company_id}",
                "branch:{$request->branch_id}"
            ];
            $token = $user->createToken('MatraErpToken', $abilities)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Company and branch selected successfully.',
                'token' => $token,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'is_vatable' => $company->is_vatable,
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to select company and branch.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function profile(Request $request)
    {
        try {
            // ✅ 1. Extract data from middleware (injected automatically)
            $userId = $request->user_id;
            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            if (!$userId) {

                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            // ✅ 2. Fetch user from CENTRAL database (never from tenant)
            $user = \App\Models\User::on('mysql')->with('roles')->find($userId);

            if (!$user) {

                return response()->json(['success' => false, 'message' => 'User not found in central database.'], 404);
            }




            if (!$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {

                return response()->json(['success' => false, 'message' => 'Unauthorized role.'], 403);
            }


            $tenant = \App\Models\Tenant::on('mysql')->where('data->company_id', $companyId)->first();

            if (!$tenant) {

                return response()->json(['success' => false, 'message' => 'Tenant not found.'], 404);
            }

            // ✅ 6. Switch connection to tenant database
            \App\Providers\TenantInitializer::switchTenant($tenant);
            config(['database.default' => 'tenant']);

            // ✅ 7. Fetch company and active branches from tenant DB
            $company = \App\Models\Company::on('mysql')->where('id', $companyId)
                ->select('id', 'name', 'is_vatable')
                ->with([
                    'branches' => fn($q) => $q->select('id', 'name', 'company_id')
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                ])
                ->first();

            if (!$company) {

                return response()->json(['success' => false, 'message' => 'Company not found in tenant DB.'], 404);
            }

            // ✅ 8. Fetch branch (if any)
            $branch = null;
            if ($branchId) {
                $branch = \App\Models\Branch::where('id', $branchId)
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->select('id', 'name', 'company_id')
                    ->first();
            }

            // ✅ 9. Send final response
            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->roles->pluck('name')->first(),
                    ],
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'is_vatable' => $company->is_vatable,
                        'branches' => $company->branches->map(fn($b) => [
                            'id' => $b->id,
                            'name' => $b->name,
                        ])->toArray(),
                    ],
                    'selected_branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'company_id' => $branch->company_id,
                    ] : null,
                ],
            ], 200);

        } catch (\Throwable $e) {


            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }




    public function getMasterUserCompanies(Request $request, $masterUserId)
    {
        try {
            $masterUser = User::where('id', $masterUserId)
                ->whereHas('roles', fn($query) => $query->where('name', 'master_user'))
                ->first();

            if (!$masterUser) {

                return response()->json([
                    'success' => false,
                    'message' => 'Master user not found',
                ], 404);
            }




            $companyIds = $masterUser->companies()->pluck('companies.id');

            if ($companyIds->isEmpty()) {

                return response()->json([
                    'success' => true,
                    'message' => 'No companies associated with this master user',
                    'data' => [],
                ], 200);
            }

            $admins = User::query()
                ->role('company_admin')
                ->whereHas('companies', fn($q) => $q->whereIn('companies.id', $companyIds))
                ->select('id', 'name', 'email')
                ->get()
                ->map(function ($admin) {
                    return [
                        'admin_id' => $admin->id,
                        'admin_name' => $admin->name,
                        'admin_email' => $admin->email,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Company admins for master user retrieved successfully',
                'data' => $admins,
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve company admins.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    public function getUserCompaniesAndBranches($userId)
    {
        try {
          
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            
            $companies = CompanyUser::where('user_id', $user->id)
                ->with(['company:id,name,is_vatable'])
                ->get()
                ->pluck('company')
                ->filter();

            if ($companies->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No companies associated with this user',
                ], 200);
            }

            $companiesWithBranches = [];

          
            foreach ($companies as $company) {

                $tenant = Tenant::all()->first(function ($t) use ($company) {
                    $data = json_decode($t->getRawOriginal('data'), true);
                    return isset($data['company_id']) && $data['company_id'] == $company->id;
                });

                if (!$tenant) {
                    continue;
                }

                $tenantData = json_decode($tenant->getRawOriginal('data'), true);
                $tenantDb = $tenantData['database'] ?? null;

                if (!$tenantDb) {
                    continue;
                }

               
                DB::purge('tenant');
                Config::set('database.connections.tenant.database', $tenantDb);
                DB::reconnect('tenant');

             
                $branches = Branch::on('tenant')
                    ->select('id', 'name')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->get();

                $branchesArray = $branches->map(function ($branch) {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                    ];
                });

               
                $companiesWithBranches[] = [
                    'id' => $company->id,
                    'name' => $company->name,
                    'is_vatable' => $company->is_vatable,
                    'branches' => $branchesArray,
                ];
            }

         
            return response()->json([
                'success' => true,
                'message' => 'Companies and branches retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'companies' => $companiesWithBranches,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(), 
            ], 500);
        }
    }





    public function tree(Request $request)
    {
        try {
            $master = Auth::guard('api')->user();

            if (!$master || !$master->hasRole('master_user')) {
                return response()->json(['success' => false, 'message' => 'Unauthorised'], 200);
            }

            $companyIds = $master->companies()->pluck('companies.id');

            $admins = User::query()
                ->role('company_admin')
                ->whereHas('companies', fn($q) => $q->whereIn('companies.id', $companyIds))
                ->with([
                    'companies' => fn($q) => $q->select('companies.id', 'companies.name')
                        ->whereNull('companies.deleted_at'),
                    'companies.branches' => fn($q) => $q->select('branches.id', 'branches.name', 'branches.company_id')
                        ->where('branches.is_active', true)
                        ->whereNull('branches.deleted_at')
                ])
                ->select('id', 'name', 'email')
                ->get()
                ->map(function ($admin) {
                    return [
                        'admin_id' => $admin->id,
                        'admin_name' => $admin->name,
                        'admin_email' => $admin->email,
                        'companies' => $admin->companies->map(function ($company) {
                            return [
                                'company_id' => $company->id,
                                'company_name' => $company->name,
                                'branches' => $company->branches->map(fn($b) => [
                                    'branch_id' => $b->id,
                                    'branch_name' => $b->name,
                                ]),
                            ];
                        }),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $admins,
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve company admin tree.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
    public function listCompanyAdmins(Request $request)
    {
        try {
            $companyAdmins = CompanyUser::with([
                'user' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->whereHas('roles', function ($roleQuery) {
                            $roleQuery->where('name', 'company_admin');
                        })
                        ->select('id', 'name');
                }
            ])
                ->get()
                ->pluck('user')
                ->filter()
                ->unique('id')
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Company admins retrieved successfully',
                'data' => $companyAdmins,
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve company admins',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                    'different:current_password',
                ],
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::guard('api')->user();

            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user'])) {

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not authorized for company access',
                ], 200);
            }

            $currentToken = $user->currentAccessToken();
            $abilities = $currentToken->abilities;
            $companyId = null;
            $branchId = null;

            foreach ($abilities as $ability) {
                if (strpos($ability, 'company:') === 0) {
                    $companyId = str_replace('company:', '', $ability);
                } elseif (strpos($ability, 'branch:') === 0) {
                    $branchId = str_replace('branch:', '', $ability);
                }
            }

            if (!$companyId || !CompanyUser::where('user_id', $user->id)->where('company_id', $companyId)->exists()) {

                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with the selected company',
                ], 200);
            }

            if (!$branchId) {

                return response()->json([
                    'success' => false,
                    'message' => 'Branch not selected',
                ], 200);
            }

            $branch = Branch::where('id', $branchId)
                ->wheremid('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();

            if (!$branch) {

                return response()->json([
                    'success' => false,
                    'message' => 'Selected branch is invalid or not associated with the company',
                ], 200);
            }

            if ($user->hasRole('company_user')) {
                $userBranch = $user->branches()
                    ->where('branches.id', $branchId)
                    ->where('branches.company_id', $companyId)
                    ->whereNull('branches.deleted_at')
                    ->where('branches.is_active', true)
                    ->first();

                if (!$userBranch) {

                    return response()->json([
                        'success' => false,
                        'message' => 'User is not associated with the selected branch',
                    ], 200);
                }
            }

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect',
                ], 401);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            $user->currentAccessToken()->delete();


            $newAbilities = [$user->hasRole('company_admin') ? 'company_admin' : 'company_user', "company:{$companyId}", "branch:{$branchId}"];
            $newToken = $user->createToken('MatraErpToken', $newAbilities)->plainTextToken;



            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
                'data' => [
                    'token' => $newToken,
                ],
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to change password.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }



    public function logout(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {
                return response()->json(['success' => false, 'message' => 'Unauthorised'], 200);
            }

            $token = $user->currentAccessToken();

            $companyId = collect($token->abilities)->first(fn($ab) => str_starts_with($ab, 'company:'));
            $branchId = collect($token->abilities)->first(fn($ab) => str_starts_with($ab, 'branch:'));



            $isTempToken = $token->name === 'TempToken';

            $user->tokens()->delete();


            if ($user->hasRole('master_user') && !$isTempToken) {
                $tempToken = $user->createToken('TempToken', ['company_access'], now()->addMinutes(30))->plainTextToken;

                $admins = User::role('company_admin')
                    ->whereHas('companies', fn($q) => $q->whereIn('companies.id', $user->companies()->pluck('companies.id')))
                    ->select('id', 'name', 'email')
                    ->get();

                return response()->json([
                    'success' => true,
                    'step' => 'choose_admin',
                    'message' => 'Logged out. Choose another admin or the same.',
                    'token' => $tempToken,
                    'admins' => $admins,
                ], 200);
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to logout.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}