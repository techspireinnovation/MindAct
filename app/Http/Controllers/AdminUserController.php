<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CompanyUser;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role; 

class AdminUserController extends Controller
{
    /**
     * Display a listing of admin users.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AdminUser::query();
            
            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone_number', 'like', "%{$search}%")
                      ->orWhere('citizenship_number', 'like', "%{$search}%")
                      ->orWhere('pan_number', 'like', "%{$search}%");
                });
            }
            
            // Filter by role
            if ($request->has('role') && !empty($request->role)) {
                $query->where('role', $request->role);
            }
            
            // Filter by status (active/inactive based on soft deletes)
            if ($request->has('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'inactive') {
                    $query->whereNotNull('deleted_at');
                } elseif ($request->status === 'all') {
                    $query->withTrashed();
                }
            }
            
            // Sorting
            $sortField = $request->get('sort_field', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortField, $sortOrder);
            
            // Pagination
            $perPage = $request->get('per_page', 10);
            $adminUsers = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $adminUsers,
                'message' => 'Admin users retrieved successfully.'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin users.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created admin user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    // public function store(Request $request): JsonResponse
    // {
    //     // Get the current connection name
    //     $connectionName = config('database.default');
        
    //     $validator = Validator::make($request->all(), [
    //         'name' => 'required|string|max:255',
    //         'email' => [
    //             'required',
    //             'email',
    //             // Use main database connection for users table
    //             Rule::unique('mysql.users', 'email')
    //         ],
    //         'password' => 'required|string|min:8',
    //         'phone_number' => 'required|string|max:20',
    //         'address' => 'required|string',
    //         'citizenship_number' => 'required|string|max:50|unique:admin_users,citizenship_number',
    //         'pan_number' => 'nullable|string|max:20|unique:admin_users,pan_number',
    //         'company_id' => 'required|exists:mysql.companies,id', // Also fix this if needed
    //         'role' => 'required|in:admin,super_admin,manager,staff'
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     DB::beginTransaction();

    //     try {
    //         // IMPORTANT: Switch to main database for User creation
    //         config(['database.default' => 'mysql']);
            
    //         // Step 1: Create user in main database (for authentication)
    //         $user = User::create([
    //             'name' => $request->name,
    //             'email' => $request->email,
    //             'password' => Hash::make($request->password)
    //         ]);

    //         // Step 2: Create company_user in main database
    //         $companyUser = CompanyUser::create([
    //             'company_id' => $request->company_id,
    //             'user_id' => $user->id
    //         ]);

    //         // Switch back to tenant database for AdminUser creation
    //         config(['database.default' => $connectionName]);
            
    //         // Step 3: Create admin user in tenant database
    //         $adminUser = AdminUser::create([
    //             'user_id' => $user->id,
    //             'name' => $request->name,
    //             'email' => $request->email,
    //             'phone_number' => $request->phone_number,
    //             'address' => $request->address,
    //             'citizenship_number' => $request->citizenship_number,
    //             'pan_number' => $request->pan_number,
    //             'role' => $request->role
    //         ]);

    //         DB::commit();

    //         return response()->json([
    //             'success' => true,
    //             'data' => [
    //                 'main_user' => $user->makeHidden(['password', 'remember_token']),
    //                 'company_user' => $companyUser,
    //                 'admin_user' => $adminUser
    //             ],
    //             'message' => 'Admin user created successfully.'
    //         ], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         // Restore original connection
    //         config(['database.default' => $connectionName]);
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to create admin user.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

public function store(Request $request): JsonResponse
{
    // Get current user
    $currentUser = Auth::user();
    
    // CHECK AUTHORIZATION: Allow super_admin, admin, OR company_admin
    $originalConnection = config('database.default');
    config(['database.default' => 'mysql']);
    
    $isSuperAdmin = $currentUser->hasRole('super_admin');
    $isAdmin = $currentUser->hasRole('admin');
    $isCompanyAdmin = $currentUser->hasRole('company_admin');
    
    config(['database.default' => $originalConnection]);
    
    if (!$isSuperAdmin && !$isAdmin && !$isCompanyAdmin) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized: Only super admin, admin, or company admin can create users.'
        ], 403);
    }

    // Get company_id from request attributes (set by identify.tenant middleware)
    $companyId = $request->company_id;
    
    if (!$companyId) {
        return response()->json([
            'success' => false,
            'message' => 'Company context not identified. Please ensure you are accessing the correct tenant.'
        ], 422);
    }

    // Validate company exists
    try {
        $originalConnection = config('database.default');
        config(['database.default' => 'mysql']);
        
        $companyExists = \DB::table('companies')->where('id', $companyId)->exists();
        
        config(['database.default' => $originalConnection]);
        
        if (!$companyExists) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found.'
            ], 404);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error validating company.',
            'error' => $e->getMessage()
        ], 500);
    }

    // Get the current connection name (tenant connection)
    $connectionName = config('database.default');
    
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255',
        'email' => [
            'required',
            'email',
            Rule::unique('mysql.users', 'email')
        ],
        'password' => 'required|string|min:8',
        'phone_number' => 'required|string|max:20',
        'address' => 'required|string',
        'citizenship_number' => 'required|string|max:50|unique:admin_users,citizenship_number',
        'pan_number' => 'nullable|string|max:20|unique:admin_users,pan_number',
        'role' => 'required|in:user' // Only 'user' role allowed
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {
        // IMPORTANT: Switch to main database for User creation
        config(['database.default' => 'mysql']);
        
        // Step 1: Create user in main database (for authentication)
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password)
        ]);

        // Step 1.5: ASSIGN 'user' ROLE TO THE NEW USER
        $roleName = 'user'; // Always assign 'user' role
        
        // First, check if 'user' role exists
        $role = Role::where('name', $roleName)->first();
        
        if (!$role) {
            // Create the 'user' role if it doesn't exist
            $role = Role::create([
                'name' => $roleName,
                'guard_name' => 'api'
            ]);
        }
        
        // Assign 'user' role to the new user
        $user->assignRole($role);

        // Step 2: Create company_user in main database using company_id from middleware
        $companyUser = CompanyUser::create([
            'company_id' => $companyId,
            'user_id' => $user->id
        ]);

        // Switch back to tenant database for AdminUser creation
        config(['database.default' => $connectionName]);
        
        // Step 3: Create admin user in tenant database
        $adminUser = AdminUser::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'address' => $request->address,
            'citizenship_number' => $request->citizenship_number,
            'pan_number' => $request->pan_number,
            'role' => 'user' // Store as 'user' in tenant database
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'data' => [
                'main_user' => $user->makeHidden(['password', 'remember_token']),
                'company_user' => $companyUser,
                'admin_user' => $adminUser,
                'assigned_role' => 'user'
            ],
            'message' => 'User created successfully for company ID: ' . $companyId
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        // Restore original connection
        config(['database.default' => $connectionName]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to create user.',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Display the specified admin user.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $adminUser = AdminUser::find($id);
            
            if (!$adminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found.'
                ], 404);
            }

            // Get main user info
            $mainUser = null;
            try {
                $originalConnection = config('database.default');
                config(['database.default' => 'mysql']);
                $mainUser = User::find($adminUser->user_id);
                config(['database.default' => $originalConnection]);
            } catch (\Exception $e) {
                // Continue without main user info
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'admin_user' => $adminUser,
                    'main_user' => $mainUser ? $mainUser->makeHidden(['password', 'remember_token']) : null
                ],
                'message' => 'Admin user retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified admin user.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $adminUser = AdminUser::find($id);
        
        if (!$adminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $adminUser->user_id,
            'password' => 'nullable|string|min:8',
            'phone_number' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'citizenship_number' => 'sometimes|string|max:50|unique:admin_users,citizenship_number,' . $id,
            'pan_number' => 'nullable|string|max:20|unique:admin_users,pan_number,' . $id,
            'role' => 'sometimes|in:admin,super_admin,manager,staff'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Update main user
            $user = null;
            try {
                $originalConnection = config('database.default');
                config(['database.default' => 'mysql']);
                
                $user = User::find($adminUser->user_id);
                if ($user) {
                    $userData = [];
                    if ($request->has('name')) $userData['name'] = $request->name;
                    if ($request->has('email')) $userData['email'] = $request->email;
                    if ($request->has('password') && $request->password) {
                        $userData['password'] = Hash::make($request->password);
                    }
                    
                    if (!empty($userData)) {
                        $user->update($userData);
                    }
                }
                
                config(['database.default' => $originalConnection]);
            } catch (\Exception $e) {
                // Log error but continue with tenant update
                \Log::error('Failed to update main user: ' . $e->getMessage());
            }

            // Update admin user in tenant database
            $adminUserData = [];
            if ($request->has('name')) $adminUserData['name'] = $request->name;
            if ($request->has('email')) $adminUserData['email'] = $request->email;
            if ($request->has('phone_number')) $adminUserData['phone_number'] = $request->phone_number;
            if ($request->has('address')) $adminUserData['address'] = $request->address;
            if ($request->has('citizenship_number')) $adminUserData['citizenship_number'] = $request->citizenship_number;
            if ($request->has('pan_number')) $adminUserData['pan_number'] = $request->pan_number;
            if ($request->has('role')) $adminUserData['role'] = $request->role;
            
            if (!empty($adminUserData)) {
                $adminUser->update($adminUserData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'admin_user' => $adminUser->fresh(),
                    'main_user' => $user ? $user->makeHidden(['password', 'remember_token']) : null
                ],
                'message' => 'Admin user updated successfully.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified admin user.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $adminUser = AdminUser::find($id);
        
        if (!$adminUser) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found.'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Soft delete admin user from tenant database
            $adminUser->delete();

            // Also soft delete user from main database
            try {
                $originalConnection = config('database.default');
                config(['database.default' => 'mysql']);
                
                $user = User::find($adminUser->user_id);
                if ($user) {
                    $user->delete();
                }
                
                config(['database.default' => $originalConnection]);
            } catch (\Exception $e) {
                \Log::error('Failed to delete main user: ' . $e->getMessage());
                // Continue even if main user deletion fails
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Admin user deleted successfully.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete admin user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft deleted admin user.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function restore($id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $adminUser = AdminUser::withTrashed()->find($id);
            
            if (!$adminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found.'
                ], 404);
            }

            // Restore admin user from tenant database
            $adminUser->restore();

            // Restore user from main database
            try {
                $originalConnection = config('database.default');
                config(['database.default' => 'mysql']);
                
                $user = User::withTrashed()->find($adminUser->user_id);
                if ($user) {
                    $user->restore();
                }
                
                config(['database.default' => $originalConnection]);
            } catch (\Exception $e) {
                \Log::error('Failed to restore main user: ' . $e->getMessage());
                // Continue even if main user restoration fails
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $adminUser,
                'message' => 'Admin user restored successfully.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore admin user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get admin user by main user ID.
     *
     * @param int $userId
     * @return JsonResponse
     */
    public function findByUserId($userId): JsonResponse
    {
        try {
            $adminUser = AdminUser::where('user_id', $userId)->first();
            
            if (!$adminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found for this user ID.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $adminUser,
                'message' => 'Admin user retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get admin user by email.
     *
     * @param string $email
     * @return JsonResponse
     */
    public function findByEmail($email): JsonResponse
    {
        try {
            $adminUser = AdminUser::where('email', $email)->first();
            
            if (!$adminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found with this email.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $adminUser,
                'message' => 'Admin user retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current authenticated admin user profile.
     *
     * @return JsonResponse
     */
    public function profile(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $adminUser = AdminUser::where('user_id', $user->id)->first();
            
            if (!$adminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin profile not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'admin_user' => $adminUser,
                    'main_user' => $user->makeHidden(['password', 'remember_token'])
                ],
                'message' => 'Admin profile retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin profile.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get admin user statistics.
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $totalAdmins = AdminUser::count();
            $activeAdmins = AdminUser::whereNull('deleted_at')->count();
            $inactiveAdmins = AdminUser::whereNotNull('deleted_at')->count();
            
            // Get count by role
            $roles = AdminUser::select('role', DB::raw('count(*) as count'))
                            ->groupBy('role')
                            ->get()
                            ->pluck('count', 'role')
                            ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_admins' => $totalAdmins,
                    'active_admins' => $activeAdmins,
                    'inactive_admins' => $inactiveAdmins,
                    'roles_distribution' => $roles
                ],
                'message' => 'Admin statistics retrieved successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}