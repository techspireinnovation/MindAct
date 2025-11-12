<?php

namespace App\Http\Controllers;

use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
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
        try{
        \Log::info('AuthController::login Request', [
            'payload' => $request->all(),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            \Log::error('AuthController::login Validation Failed', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            \Log::error('AuthController::login Invalid Credentials', [
                'email' => $request->email,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401); // Changed to 401 for unauthorized
        }

        $user = Auth::user();

        if (!$user->hasRole('super_admin', 'api')) {
            \Log::error('AuthController::login User Not Super Admin', [
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not a super admin',
            ], 200);
        }

        $token = $user->createToken('SuperAdminToken', ['super_admin'])->plainTextToken;

        \Log::info('AuthController::login Success', [
            'user_id' => $user->id,
            'token' => $token,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Super admin login successful',
            'token' => $token,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ], 200);
    }catch (Exception $e) {
        \Log::error('AuthController::login Exception', [
            'error' => $e->getMessage(),
        ]);
        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred',
            'error' => $e->getMessage(),
        ], 500);
    }
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
            ], 200);
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
            ], 200);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 200);
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
                ], 200);
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
            ], 200);
        }


        $user->tokens()->where('abilities', '["super_admin"]')->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ], 200);
    }
}