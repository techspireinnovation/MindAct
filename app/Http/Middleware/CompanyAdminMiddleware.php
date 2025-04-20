<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompanyAdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasRole('company_admin') && $user->tokenCan('company_admin')) {
            $company = $user->company;
            if ($company) {
                $request->merge(['company_id' => $company->company_id]);
            }
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden: Company Admins only'], 403);
    }
}
