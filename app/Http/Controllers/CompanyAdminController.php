<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Http\Request;

class CompanyAdminController extends Controller
{
    // User Login API
    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = Auth::user();

        // check if it is company role
        if (!$user->hasRole('company_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Not a Company Admin',
            ]);
        }

        $token = $user->createToken('MatraErpToken')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Company Admin Login successful.',
            'token' => $token,
            'user' => $user,
        ]);
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
}
