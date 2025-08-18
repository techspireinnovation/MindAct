<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\CompanyUser;

// class CompanyAccessMiddleware
// {


//     public function handle(Request $request, Closure $next): Response
//     {
//         $user = Auth::guard('api')->user();

//         \Log::info('CompanyAccessMiddleware: Processing request', [
//             'url' => $request->url(),
//             'method' => $request->method(),
//             'user_id' => $user ? $user->id : null,
//             'user_email' => $user ? $user->email : null,
//             'token' => $request->bearerToken(),
//             'token_abilities' => $user && $user->currentAccessToken() ? $user->currentAccessToken()->abilities : null,
//             'request_data' => $request->all(),
//         ]);

//         if (!$user) {
//             \Log::error('CompanyAccessMiddleware: User not authenticated', [
//                 'request' => $request->all(),
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Unauthenticated.',
//             ], 401);
//         }

//         if (!$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {
//             \Log::error('CompanyAccessMiddleware: User lacks required role', [
//                 'user_id' => $user->id,
//                 'roles' => $user->roles->pluck('name')->toArray(),
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Unauthorized: Not authorized for company access',
//             ], 200);
//         }

//         $token = $user->currentAccessToken();
//         if (!$token) {
//             \Log::error('CompanyAccessMiddleware: No current token found', [
//                 'user_id' => $user->id,
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Unauthenticated: No token found.',
//             ], 401);
//         }

//         $companyId = $request->input('company_id');
//         $branchId = $request->input('branch_id');

//         if (!$companyId && $user->hasRole('master_user')) {
//             $companyIds = $user->companies()->pluck('companies.id')->toArray();
//             if (empty($companyIds)) {
//                 \Log::error('CompanyAccessMiddleware: No companies assigned to master_user', [
//                     'user_id' => $user->id,
//                 ]);
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Forbidden: No companies assigned to master user',
//                 ], 200);
//             }
//             $companyId = $companyIds[0];
//         } elseif (!$companyId) {
//             $companyScope = collect($token->abilities)
//                 ->first(fn($ability) => str_starts_with($ability, 'company:'));
//             if ($companyScope) {
//                 $companyId = explode(':', $companyScope)[1];
//                 \Log::info('CompanyAccessMiddleware: Derived company_id from token', [
//                     'user_id' => $user->id,
//                     'company_id' => $companyId,
//                     'token_abilities' => $token->abilities,
//                 ]);
//             } else {
//                 $companyUser = CompanyUser::where('user_id', $user->id)->first();
//                 if ($companyUser) {
//                     $companyId = $companyUser->company_id;
//                     \Log::info('CompanyAccessMiddleware: Derived company_id from company_users', [
//                         'user_id' => $user->id,
//                         'company_id' => $companyId,
//                     ]);
//                 }
//             }
//         }

//         if (!$companyId) {
//             \Log::error('CompanyAccessMiddleware: No company_id could be derived', [
//                 'user_id' => $user->id,
//                 'token_abilities' => $token->abilities,
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Forbidden: No associated company found for user',
//             ], 200);
//         }

//         // Derive branch_id from token if not provided
//         if (!$branchId) {
//             $branchScope = collect($token->abilities)
//                 ->first(fn($ability) => str_starts_with($ability, 'branch:'));
//             if ($branchScope) {
//                 $branchId = explode(':', $branchScope)[1];
//                 \Log::info('CompanyAccessMiddleware: Derived branch_id from token', [
//                     'user_id' => $user->id,
//                     'branch_id' => $branchId,
//                     'token_abilities' => $token->abilities,
//                 ]);
//             }
//         }

//         if (!$branchId) {
//             \Log::error('CompanyAccessMiddleware: No branch_id could be derived', [
//                 'user_id' => $user->id,
//                 'company_id' => $companyId,
//                 'token_abilities' => $token->abilities,
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Forbidden: No associated branch found for user',
//             ], 200);
//         }

//         $companyUser = CompanyUser::where('user_id', $user->id)
//             ->where('company_id', $companyId)
//             ->first();

//         if (!$companyUser) {
//             \Log::error('CompanyAccessMiddleware: User not associated with company', [
//                 'user_id' => $user->id,
//                 'company_id' => $companyId,
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Forbidden: User not associated with the selected company',
//             ], 200);
//         }

//         $company = \App\Models\Company::where('id', $companyId)
//             ->whereNull('deleted_at')
//             ->first();

//         if (!$company) {
//             \Log::error('CompanyAccessMiddleware: Company not found or soft-deleted', [
//                 'user_id' => $user->id,
//                 'company_id' => $companyId,
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Forbidden: Company not found or deleted',
//             ], 200);
//         }

//         $branch = \App\Models\Branch::where('id', $branchId)
//             ->where('company_id', $companyId)
//             ->whereNull('deleted_at')
//             ->where('is_active', true)
//             ->first();

//         if (!$branch) {
//             \Log::error('CompanyAccessMiddleware: Branch not found or invalid', [
//                 'user_id' => $user->id,
//                 'company_id' => $companyId,
//                 'branch_id' => $branchId,
//             ]);
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Forbidden: Branch not found or invalid',
//             ], 200);
//         }

//         // Validate branch access for company_user
//         if ($user->hasRole('company_user')) {
//             $userBranch = $user->branches()
//                 ->where('branches.id', $branchId)
//                 ->where('branches.company_id', $companyId)
//                 ->whereNull('branches.deleted_at')
//                 ->where('branches.is_active', true)
//                 ->first();

//             if (!$userBranch) {
//                 \Log::error('CompanyAccessMiddleware: User not associated with branch', [
//                     'user_id' => $user->id,
//                     'branch_id' => $branchId,
//                     'company_id' => $companyId,
//                 ]);
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Forbidden: User not associated with the selected branch',
//                 ], 200);
//             }
//         }

//         $request->merge([
//             'company_id' => $companyId,
//             'branch_id' => $branchId,
//         ]);
//         \Log::info('CompanyAccessMiddleware: Merged company_id and branch_id into request', [
//             'user_id' => $user->id,
//             'company_id' => $companyId,
//             'branch_id' => $branchId,
//             'request_data_after_merge' => $request->all(),
//         ]);

//         return $next($request);
//     }
// }



class CompanyAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('api')->user();

        Log::info('CompanyAccessMiddleware: Processing request', [
            'url' => $request->url(),
            'method' => $request->method(),
            'user_id' => $user ? $user->id : null,
            'user_email' => $user ? $user->email : null,
            'token' => $request->bearerToken(),
            'token_abilities' => $user && $user->currentAccessToken() ? $user->currentAccessToken()->abilities : null,
            'request_data' => $request->all(),
        ]);

        if (!$user) {
            Log::error('CompanyAccessMiddleware: User not authenticated', [
                'request' => $request->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated: Please log in.',
            ], 401);
        }

        if (!$user->hasAnyRole(['company_admin', 'company_user', 'master_user'])) {
            Log::error('CompanyAccessMiddleware: User lacks required role', [
                'user_id' => $user->id,
                'roles' => $user->roles->pluck('name')->toArray(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: User does not have required role (company_admin, company_user, or master_user).',
            ], 200);
        }

        $token = $user->currentAccessToken();
        if (!$token) {
            Log::error('CompanyAccessMiddleware: No current token found', [
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated: No valid token found.',
            ], 401);
        }

        $companyId = $request->input('company_id');
        if (!$companyId) {
            $companyScope = collect($token->abilities)
                ->first(fn($ability) => str_starts_with($ability, 'company:'));
            if ($companyScope) {
                $companyId = explode(':', $companyScope)[1];
                Log::info('CompanyAccessMiddleware: Derived company_id from token', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
            } else {
                $companyUser = CompanyUser::where('user_id', $user->id)->first();
                if ($companyUser) {
                    $companyId = $companyUser->company_id;
                    Log::info('CompanyAccessMiddleware: Derived company_id from company_users', [
                        'user_id' => $user->id,
                        'company_id' => $companyId,
                    ]);
                }
            }
        }

        if (!$companyId) {
            Log::error('CompanyAccessMiddleware: No company_id could be derived', [
                'user_id' => $user->id,
                'token_abilities' => $token->abilities,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: No associated company found for user.',
            ], 200);
        }

        $branchId = $request->input('branch_id');
        if (!$branchId) {
            $branchScope = collect($token->abilities)
                ->first(fn($ability) => str_starts_with($ability, 'branch:'));
            if ($branchScope) {
                $branchId = explode(':', $branchScope)[1];
                Log::info('CompanyAccessMiddleware: Derived branch_id from token', [
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                ]);
            }
        }

        $company = Company::where('id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$company) {
            Log::error('CompanyAccessMiddleware: Company not found or soft-deleted', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Company not found or deleted.',
            ], 200);
        }

        $companyUser = CompanyUser::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if (!$companyUser) {
            Log::error('CompanyAccessMiddleware: User not associated with company', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: User not associated with the specified company.',
            ], 200);
        }

        if ($branchId) {
            $branch = Branch::where('id', $branchId)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();

            if (!$branch) {
                Log::error('CompanyAccessMiddleware: Branch not found or invalid', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: Branch not found or invalid.',
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
                    Log::error('CompanyAccessMiddleware: User not associated with branch', [
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'company_id' => $companyId,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Forbidden: User not associated with the specified branch.',
                    ], 200);
                }
            }
        }

        $request->merge([
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);

        Log::info('CompanyAccessMiddleware: Request authorized', [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'request_data_after_merge' => $request->all(),
        ]);

        return $next($request);
    }
}