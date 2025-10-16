<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Providers\TenantInitializer;
use Illuminate\Support\Facades\Log;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user(); // Get authenticated user

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $token = $user->currentAccessToken();
        if (!$token) {
            return response()->json(['error' => 'Token not found'], 401);
        }

        // Extract company_id from token abilities
        $companyAbility = collect($token->abilities)->first(fn($ability) => str_starts_with($ability, 'company:'));
        if (!$companyAbility) {
            return response()->json(['error' => 'No company selected'], 403);
        }
        $companyId = intval(str_replace('company:', '', $companyAbility));

        // Extract branch_id from token abilities
        $branchAbility = collect($token->abilities)->first(fn($ability) => str_starts_with($ability, 'branch:'));
        $branchId = $branchAbility ? intval(str_replace('branch:', '', $branchAbility)) : null;

        // Find tenant for the company
        $tenant = \App\Models\Tenant::where('data->company_id', $companyId)->first();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Switch tenant DB
        \App\Providers\TenantInitializer::switchTenant($tenant);

        // \Illuminate\Database\Eloquent\Model::resolveConnectionUsing(function ($connection = null) {
        //     return \DB::connection('tenant');
        config(['database.default' => 'tenant']);
        // });
        $request->merge([
            'company_id' => $companyId,
            'branch_id' => $branchId
        ]);

        return $next($request);
    }

}
