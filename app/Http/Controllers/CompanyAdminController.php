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
    // public function login(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'email' => 'required|email',
    //         'password' => 'required|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     if (!Auth::attempt($request->only('email', 'password'))) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Invalid credentials',
    //         ], 401);
    //     }

    //     $user = Auth::user();

    //     if (!$user->hasAnyRole(['company_admin', 'company_user'])) {
    //         Auth::logout();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Login failed. Not authorized for company access',
    //         ], 403);
    //     }

    //     // Fetch companies associated with the user
    //     $companies = CompanyUser::where('user_id', $user->id)
    //         ->with(['company' => function ($query) {
    //             $query->select('id', 'name')->whereNull('deleted_at');
    //         }])
    //         ->get()
    //         ->pluck('company')
    //         ->filter()
    //         ->values();

    //     if ($companies->isEmpty()) {
    //         Auth::logout();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No companies associated with this user',
    //         ], 403);
    //     }

    //     // Fetch branches associated with the user for company_user, or all active branches for company_admin
    //     $branches = $user->hasRole('company_admin')
    //         ? Branch::whereIn('company_id', $companies->pluck('id'))
    //             ->whereNull('deleted_at')
    //             ->where('is_active', true)
    //             ->select('id', 'name', 'company_id')
    //             ->get()
    //         : $user->branches()
    //             ->whereNull('branches.deleted_at')
    //             ->where('branches.is_active', true)
    //             ->select('branches.id', 'branches.name', 'branches.company_id')
    //             ->get();

    //     // Issue a temporary token for the selectCompany request
    //     $tempToken = $user->createToken('TempAuthToken', ['company_access'])->plainTextToken;

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Authentication successful. Please select a company and branch.',
    //         'data' => [
    //             'user' => [
    //                 'id' => $user->id,
    //                 'name' => $user->name,
    //                 'email' => $user->email,
    //                 'role' => $user->hasRole('company_admin') ? 'company_admin' : 'company_user',
    //             ],
    //             'companies' => $companies,
    //             'branches' => $branches,
    //             'token' => $tempToken,
    //         ],
    //     ], 200);
    // }

    // public function selectCompany(Request $request)
    // {
    //     \Log::info('selectCompany Request', [
    //         'headers' => $request->headers->all(),
    //         'payload' => $request->all(),
    //         'user' => Auth::user(),
    //         'has_company_access' => Auth::user() ? Auth::user()->hasAnyRole(['company_admin', 'company_user']) : false,
    //     ]);

    //     $rules = [
    //         'company_id' => 'required|exists:companies,id,deleted_at,NULL',
    //         'branch_id' => 'required|exists:branches,id,deleted_at,NULL',
    //     ];

    //     $validator = Validator::make($request->all(), $rules);

    //     if ($validator->fails()) {
    //         \Log::error('selectCompany Validation Failed', $validator->errors()->toArray());
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     $user = Auth::guard('api')->user();

    //     if (!$user || !$user->hasAnyRole(['company_admin', 'company_user'])) {
    //         \Log::error('selectCompany Auth Failed', [
    //             'user' => $user,
    //             'has_role' => $user ? $user->hasAnyRole(['company_admin', 'company_user']) : false,
    //         ]);
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Unauthorized: Company access required',
    //         ], 403);
    //     }

    //     // Verify the user is associated with the selected company
    //     $companyUser = CompanyUser::where('user_id', $user->id)
    //         ->where('company_id', $request->company_id)
    //         ->first();

    //     if (!$companyUser) {
    //         \Log::error('selectCompany Company Association Failed', [
    //             'user_id' => $user->id,
    //             'company_id' => $request->company_id,
    //         ]);
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'User is not associated with the selected company',
    //         ], 403);
    //     }

    //     // Verify branch exists and belongs to the selected company
    //     $branch = Branch::where('id', $request->branch_id)
    //         ->where('company_id', $request->company_id)
    //         ->whereNull('deleted_at')
    //         ->where('is_active', true)
    //         ->first();

    //     if (!$branch) {
    //         \Log::error('selectCompany Branch Association Failed', [
    //             'user_id' => $user->id,
    //             'branch_id' => $request->branch_id,
    //             'company_id' => $request->company_id,
    //         ]);
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Selected branch is invalid or not associated with the company',
    //         ], 403);
    //     }

    //     // For company_user, verify explicit branch association
    //     if ($user->hasRole('company_user')) {
    //         $userBranch = $user->branches()
    //             ->where('branches.id', $request->branch_id)
    //             ->where('branches.company_id', $request->company_id)
    //             ->whereNull('branches.deleted_at')
    //             ->where('branches.is_active', true)
    //             ->first();

    //         if (!$userBranch) {
    //             \Log::error('selectCompany Branch Association Failed for company_user', [
    //                 'user_id' => $user->id,
    //                 'branch_id' => $request->branch_id,
    //                 'company_id' => $request->company_id,
    //             ]);
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'User is not associated with the selected branch',
    //             ], 403);
    //         }
    //     }

    //     // Revoke old tokens
    //     $user->tokens()->delete();

    //     // Create token with correct abilities
    //     $abilities = [$user->hasRole('company_admin') ? 'company_admin' : 'company_user', "company:{$request->company_id}", "branch:{$request->branch_id}"];
    //     $token = $user->createToken('MatraErpToken', $abilities)->plainTextToken;
    //     \Log::info('selectCompany Token Created', [
    //         'user_id' => $user->id,
    //         'company_id' => $request->company_id,
    //         'branch_id' => $request->branch_id,
    //         'abilities' => $abilities,
    //         'token' => $token,
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Company and branch selected successfully.',
    //         'token' => $token,
    //         'data' => [
    //             'user' => [
    //                 'id' => $user->id,
    //                 'name' => $user->name,
    //                 'email' => $user->email,
    //                 'role' => $user->hasRole('company_admin') ? 'company_admin' : 'company_user',
    //             ],
    //             'company_id' => $request->company_id,
    //             'branch_id' => $request->branch_id,
    //         ],
    //     ], 200);
    // }



    public function login(Request $request)
    {
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
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }
        $user = Auth::user();
        if (!$user->hasAnyRole(['company_admin', 'company_user'])) {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Not authorized for company access',
            ], 403);
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
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'No companies associated with this user',
            ], 403);
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
        $tempToken = $user->createToken('TempAuthToken', ['company_access'])->plainTextToken;
        return response()->json([
            'success' => true,
            'message' => 'Authentication successful. Please select a company and branch.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->hasRole('company_admin') ? 'company_admin' : 'company_user',
                ],
                'companies' => $companies,
                'branches' => $branches,
                'token' => $tempToken,
            ],
        ], 200);
    }
    public function selectCompany(Request $request)
    {
        \Log::info('selectCompany Request', [
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
            'user' => Auth::user(),
            'has_company_access' => Auth::user() ? Auth::user()->hasAnyRole(['company_admin', 'company_user']) : false,
        ]);
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
        if (!$user || !$user->hasAnyRole(['company_admin', 'company_user'])) {
            \Log::error('selectCompany Auth Failed', [
                'user' => $user,
                'has_role' => $user ? $user->hasAnyRole(['company_admin', 'company_user']) : false,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Company access required',
            ], 403);
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
            ], 403);
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
            ], 403);
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
                ], 403);
            }
        }
        $company = Company::where('id', $request->company_id)
            ->select('id', 'name', 'is_vatable')
            ->first();
        $user->tokens()->delete();
        $abilities = [$user->hasRole('company_admin') ? 'company_admin' : 'company_user', "company:{$request->company_id}", "branch:{$request->branch_id}"];
        $token = $user->createToken('MatraErpToken', $abilities)->plainTextToken;
        \Log::info('selectCompany Token Created', [
            'user_id' => $user->id,
            'company_id' => $request->company_id,
            'branch_id' => $request->branch_id,
            'abilities' => $abilities,
            'token' => $token,
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Company and branch selected successfully.',
            'token' => $token,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->hasRole('company_admin') ? 'company_admin' : 'company_user',
                ],
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'is_vatable' => $company->is_vatable,
                ],
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'company_id' => $branch->company_id,
                ],
            ],
        ], 200);
    }
    public function getUserCompaniesAndBranches($userId)
    {
        // Find the user
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Get companies associated with the user
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
            ], 403);
        }

        // Get branches (role-based logic remains the same)
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
                    'role' => $user->hasRole('company_admin') ? 'company_admin' : ($user->hasRole('company_user') ? 'company_user' : 'none'),
                ],
                'companies' => $companies,
                'branches' => $branches,
            ],
        ], 200);
    }
    public function listCompanyAdmins(Request $request)
    {
        try {
           
            $companyAdmins = CompanyUser::with(['user' => function ($query) {
                $query->where('is_active', 1)
                      ->whereNull('deleted_at')
                      ->whereHas('roles', function ($roleQuery) {
                          $roleQuery->where('name', 'company_admin');
                      })
                      ->select('id', 'name');
            }])
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
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
        public function changePassword(Request $request)
    {
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
            ], 403);
        }

        // Verify company and branch association
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
            ], 403);
        }

        if (!$branchId) {
            \Log::error('changePassword Branch Not Provided', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Branch not selected',
            ], 403);
        }

        $branch = Branch::where('id', $branchId)
            ->where('company_id', $companyId)
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
            ], 403);
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
                ], 403);
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

        // Revoke all tokens
        $user->tokens()->delete();

        // Create new token with appropriate abilities
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
    }

    public function profile(Request $request)
    {
        $user = Auth::guard('api')->user();

        if (!$user || !$user->hasAnyRole(['company_admin', 'company_user'])) {
            \Log::error('profile Auth Failed', [
                'user' => $user,
                'has_role' => $user ? $user->hasAnyRole(['company_admin', 'company_user']) : false,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not authorized for company access',
            ], 403);
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

        // Verify company association
        if (!$companyId || !CompanyUser::where('user_id', $user->id)->where('company_id', $companyId)->exists()) {
            \Log::error('profile Company Association Failed', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'User is not associated with the selected company',
            ], 403);
        }

        // Verify branch association
        $branch = null;
        if ($branchId) {
            $branch = Branch::where('id', $branchId)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->select('id', 'name', 'company_id')
                ->first();

            if (!$branch) {
                \Log::error('profile Branch Association Failed', [
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'company_id' => $companyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Selected branch is invalid or not associated with the company',
                ], 403);
            }

            if ($user->hasRole('company_user')) {
                $userBranch = $user->branches()
                    ->where('branches.id', $branchId)
                    ->where('branches.company_id', $companyId)
                    ->whereNull('branches.deleted_at')
                    ->where('branches.is_active', true)
                    ->select('branches.id', 'branches.name', 'branches.company_id')
                    ->first();

                if (!$userBranch) {
                    \Log::error('profile Branch Association Failed for company_user', [
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'company_id' => $companyId,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'User is not associated with the selected branch',
                    ], 403);
                }
            }
        } else {
            \Log::error('profile Branch Not Provided', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Branch not selected',
            ], 403);
        }

        // Fetch company details
        $company = $companyId ? CompanyUser::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->with([
                'company' => function ($query) {
                    $query->select('id', 'name')->whereNull('deleted_at');
                }
            ])
            ->first()
                ?->company : null;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->hasRole('company_admin') ? 'company_admin' : 'company_user',
                'company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                ] : null,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'company_id' => $branch->company_id,
                ] : null,
            ],
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = Auth::guard('api')->user();

        if (!$user || !$user->hasAnyRole(['company_admin', 'company_user'])) {
            \Log::error('logout Auth Failed', [
                'user' => $user,
                'has_role' => $user ? $user->hasAnyRole(['company_admin', 'company_user']) : false,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not authorized for company access',
            ], 403);
        }

        // Extract company_id and branch_id for logging
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

        \Log::info('logout Request', [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'has_company_access' => $user->hasAnyRole(['company_admin', 'company_user']),
        ]);

        // Revoke all tokens for the user
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ], 200);
    }
}