<?php

namespace App\Http\Controllers\Permissions;

use App\Helpers\PermissionsHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allPermissions = PermissionsHelper::getPermissionsArray();
        $permissionsResources = [];
        sort($allPermissions);
        foreach ($allPermissions as $permission) {
            $found = Permission::where('name', 'LIKE', '%' . $permission . '%')->get();
            if ($found->count())
                $permissionsResources[$permission][] = $found;
        }
        return response()->json($permissionsResources);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        // only company admin have this allowed to use
        if (!$user || !$user->hasRole('company_admin') || !$user->tokenCan('company_admin')) {
            abort(403, message: 'Unauthorized action');
        }
        $user = User::find($id);
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'numeric' // or 'integer' for IDs
        ]);

        // Sync permissions by name (or IDs if using integer validation)
        $user->syncPermissions($request->input('permissions'));

        return response()->json(['message' => 'Permissions updated']);
    }


}
