<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;
use Validator;

class AuthController extends Controller
{
    /**
     * Register a new super_admin user
     */
    public function register(Request $request)
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => [
            'required',
            'string',
            'min:8',
            'confirmed',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
    ]);

    // Create or get the super_admin role with api guard
    $role = Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'api',
    ]);

  
    $user->assignRole($role);

  
    $token = $user->createToken('SuperAdminToken', ['super_admin'])->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Super admin registered successfully',
        'token' => $token,
        'data' => [
           
            'user' => $user,
        ],
    ], 201);
}

    /**
     * Log in a super_admin user
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }
    
        $user = Auth::user();
    
        if (!$user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not a super admin',
            ], 403);
        }
    
       
        $token = $user->createToken('SuperAdminToken', ['super_admin'])->plainTextToken;
    
        return response()->json([
            'success' => true,
            'message' => 'Super admin login successfull',
            'token' => $token,
            'data' => [
                
                'user' => $user,
            ],
        ], 200);
    }

    /**
     * Get the authenticated super_admin profile
     */
    public function profile(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not a super admin',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => [
                'user' => $user,
            ],
        ], 200);
    }

    /**
     * Change the super_admin password (affects only the current super_admin token)
     */
    public function changePassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'current_password' => 'required|string',
        'new_password' => [
            'required',
            'string',
            'min:8',
            'confirmed',
            'different:current_password',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    $user = $request->user();

    if (!$user->hasRole('super_admin')) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized: Not a super admin',
        ], 403);
    }

    if (!Hash::check($request->current_password, $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Current password is incorrect',
        ], 401);
    }

    $user->password = Hash::make($request->new_password);
    $user->save();

    
    $user->tokens()->where('abilities', '["super_admin"]')->delete();

    
    $newToken = $user->createToken('SuperAdminToken', ['super_admin'])->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Password changed successfully',
        'data' => [
            'access_token' => $newToken,
        ],
    ], 200);
}

    /**
     * Update the super_admin profile
     */
    public function update(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user->hasRole('super_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a super admin',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user->update($request->only(['name']));

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $user,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Log out the super_admin (affects only the current token)
     */
    public function logout(Request $request)
    {
        $user = $request->user();
    
        if (!$user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not a super admin',
            ], 403);
        }
    
        
        $user->tokens()->where('abilities', '["super_admin"]')->delete();
    
        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ], 200);
    }
}