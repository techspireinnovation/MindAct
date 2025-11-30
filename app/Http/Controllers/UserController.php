<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\CompanyUser;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            // Log request for debugging
            

            // Get company_id from authenticated user
            $company_id = auth()->user()->company_id ?? null;
            if (!$company_id) {
              
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticated user must have a company_id',
                ], 200);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users')->whereNull('deleted_at'),
                ],
                'password' => ['required', 'string', 'min:8'],
                'branch_ids' => [
                    'required',
                    'array',
                    Rule::exists('branches', 'id')->where(function ($query) use ($company_id) {
                        return $query->where('company_id', $company_id)->whereNull('deleted_at');
                    }),
                ],
                'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'api')->whereNull('deleted_at')],
            ]);

            if ($validator->fails()) {
               
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // Create CompanyUser record
            CompanyUser::create([
                'user_id' => $user->id,
                'company_id' => $company_id,
            ]);

            // Assign role using role_id
            $role = Role::findOrFail($request->role_id);
            $user->assignRole($role->name);

            // Assign branches
            $user->branches()->sync($request->branch_ids);

            // Log success
           
            return response()->json([
                'success' => true,
                'message' => 'User created successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'company_id' => $company_id,
                        'branches' => $user->branches->pluck('name'),
                        'roles' => $user->roles->pluck('name'),
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function userList(Request $request)
    {
        try {
            

            if (!$request->company_id) {
              
                return response()->json([
                    'success' => false,
                    'message' => 'Company ID is required',
                ], 400);
            }

            $users = User::whereHas('companies', function ($query) use ($request) {
                $query->where('company_id', $request->company_id);
            })
                ->whereNull('deleted_at')
                ->get(['id', 'name'])
                ->map(fn($user) => ['id' => $user->id, 'name' => $user->name])
                ->values();

            return response()->json([
                'message' => 'User List Received !!',
                'data' => $users
            ]);
        } catch (\Exception $e) {
           
            return response()->json([
                'error' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function userDetail(Request $request, $identifier): JsonResponse
    {
        try {
           

            // Get company_id from authenticated user
            $company_id = auth()->user()->company_id ?? null;
            if (!$company_id) {
               
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticated user must have a company_id',
                ], 200);
            }

            // Query user by ID or name
            $user = User::whereHas('companies', function ($query) use ($company_id) {
                $query->where('company_id', $company_id);
            })
                ->whereNull('deleted_at')
                ->where(function ($query) use ($identifier) {
                    $query->where('id', $identifier)
                        ->orWhere('name', $identifier);
                })
                ->with([
                    'branches' => function ($query) use ($company_id) {
                        $query->where('company_id', $company_id)->whereNull('deleted_at');
                    },
                    'roles'
                ])
                ->first();

            if (!$user) {
                
                return response()->json([
                    'success' => false,
                    'message' => 'User not found or does not belong to your company',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'company_id' => $company_id,
                        'branches' => $user->branches->map(fn($branch) => [
                            'id' => $branch->id,
                            'name' => $branch->name,
                            'is_primary' => $branch->is_primary,
                            'is_active' => $branch->is_active,
                        ]),
                        'roles' => $user->roles->pluck('name'),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
          
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving user details: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function index(Request $request): JsonResponse
    {
        try {
            // Get company_id from authenticated user
            $company_id = auth()->user()->company_id ?? null;
            if (!$company_id) {
                
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticated user must have a company_id',
                ], 200);
            }

            // Log request
            

            // Query users for the company with role_id = 3
            $users = User::whereHas('companies', function ($query) use ($company_id) {
                $query->where('company_id', $company_id);
            })
                ->whereHas('roles', function ($query) {
                    $query->where('id', 3); // Filter by role ID 3
                })
                ->with(['branches:name,id', 'roles:name,id'])
                ->whereNull('deleted_at')
                ->get();

            // Format response data
            $formattedUsers = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'company_id' => $user->companies->first()->id ?? null,
                    'branches' => $user->branches->pluck('name'),
                    'roles' => $user->roles->pluck('name'),
                ];
            });

           
            

            return response()->json([
                'success' => true,
                'message' => 'Users with role ID 3 retrieved successfully.',
                'data' => $formattedUsers,
            ], 200);
        } catch (\Exception $e) {
         
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving users: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Log request
           

            // Get company_id from authenticated user
            $company_id = auth()->user()->company_id ?? null;
            if (!$company_id) {
              
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticated user must have a company_id',
                ], 200);
            }

            // Find user
            $user = User::whereNull('deleted_at')->findOrFail($id);

            // Check if user belongs to the same company
            if (!$user->companies()->where('company_id', $company_id)->exists()) {
              
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this user',
                ], 200);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => [
                    'sometimes',
                    'email',
                    'max:255',
                    Rule::unique('users')->whereNull('deleted_at')->ignore($user->id),
                ],
                'password' => ['sometimes', 'string', 'min:8'],
                'branch_ids' => [
                    'sometimes',
                    'array',
                    Rule::exists('branches', 'id')->where(function ($query) use ($company_id) {
                        return $query->where('company_id', $company_id)->whereNull('deleted_at');
                    }),
                ],
                'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'api')->whereNull('deleted_at')],
            ]);

            if ($validator->fails()) {
               
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Update user
            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            if ($request->has('email')) {
                $updateData['email'] = $request->email;
            }
            if ($request->has('password')) {
                $updateData['password'] = Hash::make($request->password);
            }
            $user->update($updateData);

            // Update role if provided
            if ($request->has('role_id')) {
                $user->roles()->detach();
                $role = Role::findOrFail($request->role_id);
                $user->assignRole($role->name);
            }

            // Update branches if provided
            if ($request->has('branch_ids')) {
                $user->branches()->sync($request->branch_ids);
            }

            // Log success
          

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'company_id' => $company_id,
                        'branches' => $user->branches->pluck('name'),
                        'roles' => $user->roles->pluck('name'),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
          
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            // Log request
       
            // Get company_id from authenticated user
            $company_id = auth()->user()->company_id ?? null;
            if (!$company_id) {
              
            }

            // Find user
            $user = User::whereNull('deleted_at')->findOrFail($id);

            // Check if user belongs to the same company
            if (!$user->companies()->where('company_id', $company_id)->exists()) {
                
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this user',
                ], 200);
            }

            // Soft delete user
            $user->delete();

            // Remove relationships
            $user->branches()->detach();
            $user->roles()->detach();
            CompanyUser::where('user_id', $user->id)->delete();

            // Log success
           

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
         
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the user: ' . $e->getMessage(),
            ], 500);
        }
    }





}