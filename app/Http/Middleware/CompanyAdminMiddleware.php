<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\CompanyUser;

class CompanyAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('api')->user();

        \Log::info('CompanyAdminMiddleware: Processing request', [
            'url' => $request->url(),
            'headers' => $request->headers->all(),
            'user' => $user ? $user->toArray() : null,
            'token' => $request->bearerToken(),
        ]);

        if (!$user) {
            \Log::error('CompanyAdminMiddleware: User not authenticated', [
                'request' => $request->all(),
                'headers' => $request->headers->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->hasRole('company_admin', 'api')) {
            \Log::error('CompanyAdminMiddleware: User does not have company_admin role', [
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Company Admins only',
            ], 200);
        }

        $token = $user->currentAccessToken();
        if (!$token) {
            \Log::error('CompanyAdminMiddleware: No current token found', [
                'user_id' => $user->id,
                'headers' => $request->headers->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated: No token found.',
            ], 401);
        }

        $companyScope = collect($token->abilities)
            ->first(fn($ability) => str_starts_with($ability, 'company:'));

        if (!$companyScope && $request->route()->getActionMethod() !== 'selectCompany') {
            \Log::error('CompanyAdminMiddleware: Token lacks company scope', [
                'user_id' => $user->id,
                'token_abilities' => $token->abilities,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: No company selected',
            ], 200);
        }

        if ($companyScope) {
            $companyId = explode(':', $companyScope)[1];

            $companyUser = CompanyUser::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->first();

            if (!$companyUser) {
                \Log::error('CompanyAdminMiddleware: User not associated with company', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: User not associated with the selected company',
                ], 200);
            }

            $request->merge(['company_id' => $companyId]);
        }

        return $next($request);
    }
}