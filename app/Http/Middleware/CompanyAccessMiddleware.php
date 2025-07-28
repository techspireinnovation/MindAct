<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\CompanyUser;

class CompanyAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('api')->user();

        \Log::info('CompanyAccessMiddleware: Processing request', [
            'url' => $request->url(),
            'method' => $request->method(),
            'user_id' => $user ? $user->id : null,
            'user_email' => $user ? $user->email : null,
            'token' => $request->bearerToken(),
            'token_abilities' => $user && $user->currentAccessToken() ? $user->currentAccessToken()->abilities : null,
            'request_data' => $request->all(),
        ]);

        if (!$user) {
            \Log::error('CompanyAccessMiddleware: User not authenticated', [
                'request' => $request->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->hasAnyRole(['company_admin', 'company_user'])) {
            \Log::error('CompanyAccessMiddleware: User lacks required role', [
                'user_id' => $user->id,
                'roles' => $user->roles->pluck('name')->toArray(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not authorized for company access',
            ], 403);
        }

        $token = $user->currentAccessToken();
        if (!$token) {
            \Log::error('CompanyAccessMiddleware: No current token found', [
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated: No token found.',
            ], 401);
        }

        // Derive company_id from user->company, token, or company_users
        $companyId = null;

        // Try user->company relationship (like CompanyAdminMiddleware)
        if ($user->company && isset($user->company->company_id)) {
            $companyId = $user->company->company_id;
            \Log::info('CompanyAccessMiddleware: Derived company_id from user->company', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
        } else {
            // Try token abilities
            $companyScope = collect($token->abilities)
                ->first(fn($ability) => str_starts_with($ability, 'company:'));
            if ($companyScope) {
                $companyId = explode(':', $companyScope)[1];
                \Log::info('CompanyAccessMiddleware: Derived company_id from token', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'token_abilities' => $token->abilities,
                ]);
            } else {
                // Fallback to company_users
                $companyUser = CompanyUser::where('user_id', $user->id)->first();
                if ($companyUser) {
                    $companyId = $companyUser->company_id;
                    \Log::info('CompanyAccessMiddleware: Derived company_id from company_users', [
                        'user_id' => $user->id,
                        'company_id' => $companyId,
                    ]);
                }
            }
        }

        if (!$companyId) {
            \Log::error('CompanyAccessMiddleware: No company_id could be derived', [
                'user_id' => $user->id,
                'token_abilities' => $token->abilities,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: No associated company found for user',
            ], 403);
        }

        // Verify user is associated with the company
        $companyUser = CompanyUser::where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if (!$companyUser) {
            \Log::error('CompanyAccessMiddleware: User not associated with company', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: User not associated with the selected company',
            ], 403);
        }

        // Verify company exists and is not soft-deleted
        $company = \App\Models\Company::where('id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$company) {
            \Log::error('CompanyAccessMiddleware: Company not found or soft-deleted', [
                'user_id' => $user->id,
                'company_id' => $companyId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Company not found or deleted',
            ], 403);
        }

        // Merge company_id into the request
        $request->merge(['company_id' => $companyId]);
        \Log::info('CompanyAccessMiddleware: Merged company_id into request', [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'request_data_after_merge' => $request->all(),
        ]);

        return $next($request);
    }
}