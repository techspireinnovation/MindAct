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
        if ($request->user() && $request->user()->hasRole('company_admin')) {
            $user = $request->user();
            $request->merge(['company_id' => $user->company->company_id]);
            return $next($request);
        }
        return response()->json(['message' => 'Forbidden: Company Admins only'], 403);
    }
}
