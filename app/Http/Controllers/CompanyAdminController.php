<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;
use Validator;

class CompanyAdminController extends Controller
{
    
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

    if (!$user->hasRole('company_admin')) {
        return response()->json([
            'success' => false,
            'message' => 'Login failed. Not a Company Admin',
        ], 403);
    }

   
    $token = $user->createToken('MatraErpToken', ['company_admin'])->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Company Admin Login successful.',
        'token' => $token,
        'data' => [
            
            'user' => $user,
        ],
    ], 200);
}





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

    if (!$user->hasRole('company_admin')) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized: Not a company admin',
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

    // Revoke only company_admin tokens
    $user->tokens()->where('abilities', '["company_admin"]')->delete();

    // Create new company_admin token
    $newToken = $user->createToken('MatraErpToken', ['company_admin'])->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Password changed successfully',
        'data' => [
            'access_token' => $newToken,
        ],
    ], 200);
}

    // User Profile API (Protected)
    public function profile(Request $request)
    {
        $user = $request->user();
        $company = $user->company;
        $user->company_id = isset($company) ? $company->company_id : 0;
        return response()->json([
            'success' => true,
            'user' => $request->user(),
        ]);
    }

    public function logout(Request $request)
{
    $user = $request->user();

    if (!$user->hasRole('company_admin')) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized: Not a company admin',
        ], 403);
    }

    // Revoke only the current company_admin token
    $user->tokens()->where('abilities', '["company_admin"]')->delete();

    return response()->json([
        'success' => true,
        'message' => 'Company admin logout successful',
    ], 200);
}
}
