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
        sort($allPermissions);
        return response()->json($allPermissions);
    }

    public function update(Request $request, int $id)
    {
        $user = User::find($id);

        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string' // or 'integer' for IDs
        ]);

        // Sync permissions by name (or IDs if using integer validation)
        $user->syncPermissions($request->input('permissions'));

        return response()->json(['message' => 'Permissions updated']);
    }


}
