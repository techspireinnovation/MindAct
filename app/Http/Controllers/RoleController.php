<?php

namespace App\Http\Controllers;
use App\Models\Brand;
use Dotenv\Exception\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Sagautam5\LocalStateNepal\Entities\Province;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{

    public function store(Request $request)
    {
      
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean', // Allow manual setting of is_active
            ]);

            // Check if a non-deleted role with the same name exists
            if (Role::where('name', $request->name)->exists()) {
               
                return response()->json(['message' => "Role {$request->name} already exists"], 422);
            }

            $role = Role::create([
                'name' => $request->name,
                'guard_name' => 'api',
                'is_active' => $request->has('is_active') ? $request->is_active : 1, // Default to 1 if not provided
            ]);

            return response()->json(['message' => "Role {$role->name} created successfully"], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the role: ' . $e->getMessage()], 500);
        }
    }

  
    public function listRoles(Request $request)
    {
       
        try {
            $entries = Role::withoutTrashed();
            return response()->json($entries->paginate(50));
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while listing roles'], 500);
        }
    }

   

    public function editRole(Request $request)
    {
        

        try {
            $request->validate([
                'id' => 'required|exists:roles,id,deleted_at,NULL',
                'name' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean', // Allow manual setting of is_active
            ]);

            $role = Role::withoutTrashed()->findOrFail($request->id);
            if ($role->name === 'admin' || $role->name === 'user') {
                if ($request->has('is_active') && !$request->is_active) {
                    return response()->json(['message' => 'Cannot deactivate default roles'], 403);
                }
                if ($request->name !== $role->name) {
                    return response()->json(['message' => 'Cannot rename default roles'], 403);
                }
            }

            // Check if a non-deleted role with the same name exists, excluding the current role
            if (Role::withoutTrashed()->where('name', $request->name)->where('id', '!=', $request->id)->exists()) {
                return response()->json(['message' => "Role {$request->name} already exists"], 422);
            }

            $role->update([
                'name' => $request->name,
                'is_active' => $request->has('is_active') ? $request->is_active : $role->is_active, // Update is_active if provided
            ]);

            return response()->json(['message' => "Role updated to {$role->name} successfully"]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while updating the role'], 500);
        }
    }

    public function deleteRole(Request $request)
    {
        if (!$request->user()->hasPermissionTo('delete roles')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $request->validate([
                'id' => 'required|exists:roles,id,deleted_at,NULL',
            ]);

            $role = Role::withoutTrashed()->findOrFail($request->id);
            if ($role->name === 'admin' || $role->name === 'user') {
                return response()->json(['message' => 'Cannot delete default roles'], 403);
            }
            $role->delete();

            return response()->json(['message' => "Role {$role->name} deleted successfully"]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while deleting the role'], 500);
        }
    }

    public function assignRole(Request $request)
    {
       

        try {
            $request->validate([
                'email' => 'required|email|exists:users,email,deleted_at,NULL',
                'role' => 'required|string|exists:roles,name,deleted_at,NULL',
            ]);

            $user = User::withoutTrashed()->where('email', $request->email)->first();
            $role = Role::withoutTrashed()->where('name', $request->role)->first();
            $user->syncRoles([$role->name]);

            return response()->json(['message' => "Role {$role->name} assigned to user {$user->name}"]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while assigning the role'], 500);
        }
    }
   
}
