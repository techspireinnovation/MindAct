<?php

namespace App\Http\Controllers;

use App\Models\CompanyUser;
use App\Models\Company;
use App\Models\User;
use Auth;
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
            \Log::error('Login error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
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
            \Log::error('selectAdmin error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while selecting admin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function selectCompany(Request $request)
    {
        \Log::info('selectCompany Request', [
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
            'user' => Auth::user(),
            'has_company_access' => Auth::user() ? Auth::user()->hasAnyRole(['company_admin', 'company_user', 'master_user']) : false,
        ]);
    
        try {
            $rules = [
                'company_id' => 'required|exists:companies,id,deleted_at,NULL',
                'branch_id' => 'required|exists:branches,id,deleted_at,NULL',
            ];
    
            $validator = Validator::make($request->all(), $rules);
    
            if ($validator->fails()) {
                \Log::error('selectCompany Validation Failed', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }
    
            $user = Auth::guard('api')->user();
    
            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {
                \Log::error('selectCompany Auth Failed', [
                    'user' => $user,
                    'has_role' => $user ? $user->hasAnyRole(['company_admin', 'company_user', 'master_user']) : false,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Company access required',
                ], 200);
            }
    
            $companyUser = CompanyUser::where('user_id', $user->id)
                ->where('company_id', $request->company_id)
                ->first();
    
            if (!$companyUser) {
                \Log::error('selectCompany Company Association Failed', [
                    'user_id' => $user->id,
                    'company_id' => $request->company_id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with the selected company',
                ], 200);
            }
    
            $branch = Branch::where('id', $request->branch_id)
                ->where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();
    
            if (!$branch) {
                \Log::error('selectCompany Branch Association Failed', [
                    'user_id' => $user->id,
                    'branch_id' => $request->branch_id,
                    'company_id' => $request->company_id,
                ]);
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
                    \Log::error('selectCompany Branch Association Failed for company_user', [
                        'user_id' => $user->id,
                        'branch_id' => $request->branch_id,
                        'company_id' => $request->company_id,
                    ]);
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
                \Log::error('selectCompany Company Not Found', [
                    'user_id' => $user->id,
                    'company_id' => $request->company_id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Selected company not found',
                ], 404);
            }
    
            // $user->tokens()->delete();
            if ($request->user()->currentAccessToken()) {
                $request->user()->currentAccessToken()->delete();
            }
    
            $abilities = [
                $user->hasRole('company_admin') ? 'company_admin' : ($user->hasRole('master_user') ? 'master_user' : 'company_user'),
                "company:{$request->company_id}",
                "branch:{$request->branch_id}"
            ];
            $token = $user->createToken('MatraErpToken', $abilities)->plainTextToken;
    
            \Log::info('selectCompany Token Created', [
                'user_id' => $user->id,
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'abilities' => $abilities,
            ]);
    
            return response()->json([
                'success' => true,
                'message' => 'Company and branch selected successfully.',
                'token' => $token,
                'company_id' => $company->id,
                'is_vatable' => $company->is_vatable,
            ], 200);
        } catch (\Exception $e) {
            \Log::error('selectCompany Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to select company and branch.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


//     public function selectCompany(Request $request)
// {
//     $user = $request->user();

//     $validated = $request->validate([
//         'company_id' => 'required|exists:companies,id',
//     ]);

//     $companyId = $validated['company_id'];

//     if (!$user->companies()->where('companies.id', $companyId)->exists()) {
//         return response()->json([
//             'success' => false,
//             'message' => 'Unauthorized to access this company',
//         ], 403);
//     }

//     if ($request->user()->currentAccessToken()) {
//         $request->user()->currentAccessToken()->delete();
//     }

//     $abilities = ['company_access:' . $companyId];
//     $token = $user->createToken('MatraErpToken', $abilities)->plainTextToken;

//     return response()->json([
//         'success' => true,
//         'message' => 'Company selected successfully',
//         'token'   => $token,
//         'company_id' => $companyId,
//     ], 200);
// }



    public function profile(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
    
            \Log::info('Profile Request', [
                'user_id' => $user ? $user->id : null,
                'user_email' => $user ? $user->email : null,
                'roles' => $user ? $user->roles->pluck('name')->toArray() : [],
                'request_company_id' => $request->company_id,
            ]);
    
            if (!$user) {
                \Log::error('Profile: User not authenticated');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
    
            if (!$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {
                \Log::error('Profile: User lacks required role', [
                    'user_id' => $user->id,
                    'roles' => $user->roles->pluck('name')->toArray(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not authorized for company access',
                ], 200);
            }
    
            $currentToken = $user->currentAccessToken();
            $abilities = $currentToken ? $currentToken->abilities : [];
    
            $companyId = null;
            $branchId = null;
    
            foreach ($abilities as $ability) {
                if (strpos($ability, 'company:') === 0) {
                    $companyId = str_replace('company:', '', $ability);
                }
                if (strpos($ability, 'branch:') === 0) {
                    $branchId = str_replace('branch:', '', $ability);
                }
            }
    
            if (!$companyId) {
                \Log::error('Profile: Token missing company ability', [
                    'user_id' => $user->id,
                    'abilities' => $abilities,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Token does not contain company information',
                ], 200);
            }
    
            $companyUser = CompanyUser::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->first();
    
            if (!$companyUser) {
                \Log::error('Profile: User not associated with company', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with the selected company',
                ], 200);
            }
    
            $company = Company::where('id', $companyId)
                ->whereNull('deleted_at')
                ->select('id', 'name', 'is_vatable')
                ->with([
                    'branches' => fn($q) => $q->select('branches.id', 'branches.name', 'branches.company_id')
                        ->where('branches.is_active', true)
                        ->whereNull('branches.deleted_at')
                ])
                ->first();
    
            if (!$company) {
                \Log::error('Profile: Company not found or soft-deleted', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: Company not found or deleted',
                ], 200);
            }
    
            $branch = null;
            if ($branchId) {
                $branch = Branch::withoutGlobalScope(CompanyIdScope::class)
                    ->where('id', $branchId)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->select('id', 'name', 'company_id')
                    ->first();
    
                if (!$branch && !$user->hasRole('master_user')) {
                    \Log::error('Profile: Branch not found or invalid', [
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'company_id' => $companyId,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected branch is invalid or not associated with the company',
                    ], 200);
                }
            } elseif (!$user->hasRole('master_user')) {
                \Log::error('Profile: Branch ID missing for non-master user', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Branch not selected',
                ], 200);
            }
    
            if ($user->hasRole('company_user') && $branch) {
                $userBranch = $user->branches()
                    ->where('branches.id', $branchId)
                    ->where('branches.company_id', $companyId)
                    ->whereNull('branches.deleted_at')
                    ->where('branches.is_active', true)
                    ->select('branches.id', 'branches.name', 'branches.company_id')
                    ->first();
    
                if (!$userBranch) {
                    \Log::error('Profile: Branch association failed for company_user', [
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'company_id' => $companyId,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'User is not associated with the selected branch',
                    ], 200);
                }
            }
    
            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->hasRole('company_admin')
                            ? 'company_admin'
                            : ($user->hasRole('master_user')
                                ? 'master_user'
                                : 'company_user'),
                    ],
                    'company' => [
                        'company_id' => $company->id,
                        'name' => $company->name,
                        'is_vatable' => $company->is_vatable,
                        'branches' => $company->branches->map(fn($b) => [
                            'id' => $b->id,
                            'name' => $b->name,
                            'company_id' => $b->company_id,
                        ])->toArray(),
                    ],
                    'selected_branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'company_id' => $branch->company_id,
                    ] : null,
                ],
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Profile Error', [
                'user_id' => $user ? $user->id : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
                \Log::error('getMasterUserCompanies: Master user not found', [
                    'master_user_id' => $masterUserId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Master user not found',
                ], 404);
            }
    
            // Debug roles
            \Log::info('Master User Roles', [
                'user_id' => $masterUserId,
                'roles' => $masterUser->getRoleNames()->toArray(),
            ]);
    
            $companyIds = $masterUser->companies()->pluck('companies.id');
    
            if ($companyIds->isEmpty()) {
                \Log::info('getMasterUserCompanies: No companies found for master user', [
                    'master_user_id' => $masterUserId,
                ]);
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
            \Log::error('getMasterUserCompanies Error', [
                'master_user_id' => $masterUserId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
            ->with([
                'company' => function ($query) {
                    $query->select('id', 'name', 'is_vatable')->whereNull('deleted_at');
                }
            ])
            ->get()
            ->pluck('company')
            ->filter()
            ->values();

        if ($companies->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No companies associated with this user',
            ], 200);
        }

        $branches = $user->hasRole('company_admin')
            ? Branch::whereIn('company_id', $companies->pluck('id'))
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->select('id', 'name', 'company_id')
                ->get()
            : $user->branches()
                ->whereNull('branches.deleted_at')
                ->where('branches.is_active', true)
                ->select('branches.id', 'branches.name', 'branches.company_id')
                ->get();

        return response()->json([
            'success' => true,
            'message' => 'Companies and branches retrieved successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->hasRole('company_admin') ? 'company_admin' : 
                             ($user->hasRole('company_user') ? 'company_user' : 
                             ($user->hasRole('master_user') ? 'master_user' : 'none')),
                ],
                'companies' => $companies,
                'branches' => $branches,
            ],
        ], 200);
    } catch (\Exception $e) {
        \Log::error('getUserCompaniesAndBranches Error', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve user companies and branches.',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
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
            \Log::error('tree Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Company admins retrieved successfully',
                'data' => $companyAdmins,
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve company admins: ' . $e->getMessage());
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
                \Log::error('changePassword Validation Failed', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::guard('api')->user();

            if (!$user || !$user->hasAnyRole(['company_admin', 'company_user'])) {
                \Log::error('changePassword Auth Failed', [
                    'user' => $user,
                    'has_role' => $user ? $user->hasAnyRole(['company_admin', 'company_user']) : false,
                ]);
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
                \Log::error('changePassword Company Association Failed', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with the selected company',
                ], 200);
            }

            if (!$branchId) {
                \Log::error('changePassword Branch Not Provided', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
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
                \Log::error('changePassword Branch Association Failed', [
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'company_id' => $companyId,
                ]);
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
                    \Log::error('changePassword Branch Association Failed for company_user', [
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'company_id' => $companyId,
                    ]);
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

            \Log::info('changePassword Token Created', [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'abilities' => $newAbilities,
                'token' => $newToken,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
                'data' => [
                    'token' => $newToken,
                ],
            ], 200);
        } catch (\Exception $e) {
            \Log::error('changePassword Error', [
                'user_id' => $user ? $user->id : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
    
            \Log::info('Logout', [
                'user_id' => $user->id,
                'role' => $user->getRoleNames()->first(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'token_name' => $token->name,
            ]);
    
            $isTempToken = $token->name === 'TempToken';
    
            // $user->tokens()->delete();
            if ($token) {
                $token->delete();
            }
    
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
            \Log::error('logout Error', [
                'user_id' => $user ? $user->id : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}