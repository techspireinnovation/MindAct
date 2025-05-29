<?php

namespace App\Http\Middleware;

use App\Helpers\PermissionsHelper;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\Response;

class CompanyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check authorization for company admin
        if ($user && $user->hasRole('company_admin') && $user->tokenCan('company_admin')) {
            $company = $user->company;
            if ($company) {
                $request->merge(['company_id' => $company->company_id]);
            }
            //return $next($request);
        }

        // Check authorization for company staff
        if ($user && $user->hasRole('company_admin') && $user->tokenCan('company_admin')) {
            $company = $user->company;
            if ($company) {
                $request->merge(['company_id' => $company->company_id]);
            }
            $route = $request->route();
            $resource = $route->getName() ?: str_replace('/', '.', $route->uri());

            // check for valid permissions
            if (
                Permission::where('name', $resource)
                    ->where('guard_name', 'api')
                    ->exists()
            ) {
                //dd($user->givePermissionTo([$resource]));
                if (!auth()->user()->hasPermissionTo($resource)) {
                    abort(403, 'Unauthorized action');
                }
            }


            return $next($request);
        }
        return response()->json(['message' => 'Forbidden: Company Admins or Staff only'], 403);
    }
}
