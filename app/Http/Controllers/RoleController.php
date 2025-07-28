<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;


class RoleController extends Controller
{

    public function getById(Request $request, $id)
    {
       


        try {
            $entries = Role::withoutTrashed()->findOrFail($id);
            return response()->json(['role' => $entries], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Role not found: ' . $id);
            return response()->json([
                'message' => 'Role not found or already deleted',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching Role: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'An error occurred while fetching the Role',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }


   


    public function toggleActiveStatus(Request $request, $id)
    {

        try {
            $role = Role::withoutTrashed()->findOrFail($id);

            $role->is_active = !$role->is_active;
            $role->save();

            Log::debug('Role active status toggled: ID=' . $id . ', is_active=' . $role->is_active);

            return response()->json([
                'message' => "Role active status updated to " . ($role->is_active ? 'active' : 'inactive'),
                'is_active' => $role->is_active
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Role not found: ' . $id);
            return response()->json([
                'message' => 'Role not found or already deleted'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error toggling Role active status: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'An error occurred while toggling the Role active status',
                'error' => $e->getMessage()
            ], 500);
        }
    }




 

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean',
            ]);

            if (Role::where('name', $request->name)->exists()) {
                return response()->json(['message' => "Role {$request->name} already exists"], 422);
            }

            $role = Role::create([
                'name' => $request->name,
                'guard_name' => 'api',
                'is_active' => $request->has('is_active') ? $request->is_active : 1,
            ]);

            Log::info("Role {$role->name} created successfully", ['id' => $role->id]);
            return response()->json(['message' => "Role {$role->name} created successfully"], 201);
        } catch (ValidationException $e) {
            Log::error('Validation error in store role: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error creating role: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while creating the role: ' . $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $entries = Role::query()->paginate(50);
            return response()->json($entries);
        } catch (\Exception $e) {
            Log::error('Error listing roles: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while listing roles: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean',
            ]);

            $role = Role::findOrFail($id);
            if ($role->name === 'admin' || $role->name === 'user') {
                if ($request->has('is_active') && !$request->is_active) {
                    return response()->json(['message' => 'Cannot deactivate default roles'], 403);
                }
                if ($request->name !== $role->name) {
                    return response()->json(['message' => 'Cannot rename default roles'], 403);
                }
            }

            if (Role::where('name', $request->name)->where('id', '!=', $id)->exists()) {
                return response()->json(['message' => "Role {$request->name} already exists"], 422);
            }

            $role->update([
                'name' => $request->name,
                'is_active' => $request->has('is_active') ? $request->is_active : $role->is_active,
            ]);

            Log::info("Role {$role->name} updated successfully", ['id' => $role->id]);
            return response()->json(['message' => "Role updated to {$role->name} successfully"]);
        } catch (ValidationException $e) {
            Log::error('Validation error in update role: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            Log::error('Role not found: ' . $id);
            return response()->json(['message' => 'Role not found or already deleted'], 404);
        } catch (\Exception $e) {
            Log::error('Error updating role: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while updating the role: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $role = Role::findOrFail($id);
            if ($role->name === 'admin' || $role->name === 'user') {
                return response()->json(['message' => 'Cannot delete default roles'], 403);
            }

            $role->delete();
            Log::info("Role {$role->name} deleted successfully", ['id' => $role->id]);
            return response()->json(['message' => "Role {$role->name} deleted successfully"]);
        } catch (ModelNotFoundException $e) {
            Log::error('Role not found: ' . $id);
            return response()->json(['message' => 'Role not found or already deleted'], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting role: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while deleting the role: ' . $e->getMessage()], 500);
        }
    }

    public function assignRole(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email,deleted_at,NULL',
                'role' => 'required|string|exists:roles,name,deleted_at,NULL',
            ]);

            $user = User::where('email', $request->email)->firstOrFail();
            $role = Role::where('name', $request->role)->firstOrFail();
            $user->syncRoles([$role->name]);

            Log::info("Role {$role->name} assigned to user {$user->name}", ['user_id' => $user->id, 'role_id' => $role->id]);
            return response()->json(['message' => "Role {$role->name} assigned to user {$user->name}"]);
        } catch (ValidationException $e) {
            Log::error('Validation error in assign role: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            Log::error('User or role not found: ' . json_encode($request->all(), JSON_UNESCAPED_UNICODE));
            return response()->json(['message' => 'User or role not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error assigning role: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while assigning the role: ' . $e->getMessage()], 500);
        }
    }
}