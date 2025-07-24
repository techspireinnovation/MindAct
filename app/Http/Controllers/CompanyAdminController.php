<?php

namespace App\Http\Controllers;

use App\Models\CompanyUser;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Not a Company Admin',
            ], 403);
        }
    
        // Fetch companies associated with the user
        $companies = CompanyUser::where('user_id', $user->id)
            ->with(['company' => function ($query) {
                $query->select('id', 'name')->whereNull('deleted_at');
            }])
            ->get()
            ->pluck('company')
            ->filter()
            ->values();
    
        if ($companies->isEmpty()) {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'No companies associated with this admin',
            ], 403);
        }
    
        // Issue a temporary token for the selectCompany request
        $tempToken = $user->createToken('TempAuthToken', ['company_admin'])->plainTextToken;
    
        return response()->json([
            'success' => true,
            'message' => 'Authentication successful. Please select a company.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'companies' => $companies,
                'token' => $tempToken, // Include the temporary token
            ],
        ], 200);
    }
    
    /**
     * Select company and issue token
     */
    public function selectCompany(Request $request)
{
    \Log::info('selectCompany Request', [
        'headers' => $request->headers->all(),
        'payload' => $request->all(),
        'user' => Auth::user(),
        'has_company_admin_role' => Auth::user() ? Auth::user()->hasRole('company_admin', 'api') : false,
    ]);

    $validator = Validator::make($request->all(), [
        'company_id' => 'required|exists:companies,id,deleted_at,NULL',
    ]);

    if ($validator->fails()) {
        \Log::error('selectCompany Validation Failed', $validator->errors()->toArray());
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    $user = Auth::guard('api')->user();

    if (!$user || !$user->hasRole('company_admin', 'api')) {
        \Log::error('selectCompany Auth Failed', [
            'user' => $user,
            'has_role' => $user ? $user->hasRole('company_admin', 'api') : false,
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized: Company admin required',
        ], 403);
    }

    // Verify the user is associated with the selected company
    $companyUser = CompanyUser::where('user_id', $user->id)
        ->where('company_id', $request->company_id)
        ->first();

    if (!$companyUser) {
        \Log::error('selectCompany Company Association Failed', [
            'user_id' => $user->id,
            'company_id' => $request->company_id,
        ]);
        return response()->json([
            'success' => false,
            'message' => 'User is not associated with the selected company',
        ], 403);
    }

    // Revoke old tokens
    $user->tokens()->delete();

    // Create token with correct abilities
    $abilities = ['company_admin', "company:{$request->company_id}"];
    $token = $user->createToken('MatraErpToken', $abilities)->plainTextToken;
    \Log::info('selectCompany Token Created', [
        'user_id' => $user->id,
        'company_id' => $request->company_id,
        'abilities' => $abilities,
        'token' => $token,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Company selected successfully.',
        'token' => $token,
        'data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'company_id' => $request->company_id,
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
        \Log::info('logout Request', [
            'headers' => $request->headers->all(),
            'user' => $request->user(),
            'has_company_admin_role' => $request->user() ? $request->user()->hasRole('company_admin', 'api') : false,
        ]);

        $user = $request->user();

        if (!$user || !$user->hasRole('company_admin', 'api')) {
            \Log::error('logout Auth Failed', [
                'user' => $user,
                'has_role' => $user ? $user->hasRole('company_admin', 'api') : false,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Not a company admin',
            ], 403);
        }

        // Revoke all tokens for the user
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Company admin logout successful',
        ], 200);
    }
}
