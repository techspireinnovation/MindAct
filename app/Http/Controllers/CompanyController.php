<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Hash;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;


class CompanyController extends Controller
{
    // Display a listing
    public function index(): JsonResponse
    {
        return response()->json(Company::paginate(10));
    }

    // Store a new resource
    public function store(Request $request): JsonResponse
    {
      
        $user = Auth::user();
        if (!$user || !$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Super admin required',
            ], 403);
        }

        try {
          
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'licence_issue_date' => 'nullable|string|max:255',
                'working_date' => 'nullable|string|max:255',
                'reg_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'email_address' => 'nullable|string|email|max:255',
                'website' => 'nullable|string|max:255',
                'fax' => 'nullable|string|max:255',
                'logo' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'palika_name' => 'nullable|string|max:255',
                'ward_number' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_position' => 'nullable|string|max:255',
                'agreement_holder_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'license_number' => 'nullable|string|max:255',
                'activation_key' => 'nullable|string|max:255',
                'url_link' => 'nullable|string|max:255',
                'admin_email' => 'required|string|email|max:255|unique:users,email',
                'admin_name' => 'required|string|max:255',
                'password' => 'required|string|min:6|confirmed',
            ]);

    
            DB::beginTransaction();

    
            $company = Company::create($validated);

     
            $companyAdmin = User::create([
                'email' => $validated['admin_email'],
                'name' => $validated['admin_name'],
                'password' => Hash::make($validated['password']),
            ]);

     
            $role = Role::firstOrCreate([
                'name' => 'company_admin',
                'guard_name' => 'api'
            ]);

      
            $companyAdmin->assignRole($role);

           
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $companyAdmin->id
            ]);

         
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company and admin created successfully',
                'data' => [
                  
                    'company' => $company,
                    'admin' => $companyAdmin,
                    
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Company creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create company and admin',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
 * Update the specified company and its admin user in storage.
 */
    /**
 * Update the specified company and its admin user in storage.
 */

 public function update(Request $request): JsonResponse
    {
        try {
           
            $user = Auth::user();

            
            if (!$user->hasRole('company_admin') || !$user->tokenCan('company_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a company admin',
                ], 403);
            }

           
            $companyUser = CompanyUser::where('user_id', $user->id)->first();

            if (!$companyUser || !$companyUser->company) {
                return response()->json([
                    'success' => false,
                    'message' => 'No company associated with this user',
                ], 404);
            }

            $company = $companyUser->company;

           
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'licence_issue_date' => 'nullable|string|max:255',
                'working_date' => 'nullable|string|max:255',
                'reg_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'email_address' => 'nullable|string|email|max:255',
                'website' => 'nullable|string|max:255',
                'fax' => 'nullable|string|max:255',
                'logo' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'palika_name' => 'nullable|string|max:255',
                'ward_number' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_position' => 'nullable|string|max:255',
                'agreement_holder_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'license_number' => 'nullable|string|max:255',
                'activation_key' => 'nullable|string|max:255',
                'url_link' => 'nullable|string|max:255',
                'admin_name' => 'sometimes|required|string|max:255',
                'admin_email' => 'sometimes|required|string|max:255|unique:users,email,' . $user->id,
            ]);

           
            $company->update($validated);

            
            $userUpdates = [];
            if ($request->has('admin_name')) {
                $userUpdates['name'] = $validated['admin_name'];
            }
            if ($request->has('admin_email')) {
                $userUpdates['email'] = $validated['admin_email'];
            }
            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company details updated successfully',
                'data' => [
                    'company' => $company->fresh(),
                    'user' => $user->fresh(),
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


   
    public function updateCompany(Request $request, $id): JsonResponse
    {
        try {
      
            $user = Auth::user();

        
            if (!$user->hasRole('super_admin') || !$user->tokenCan('super_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Not a super admin',
                ], 403);
            }

      
            $company = Company::findOrFail($id);

         
            $companyUser = CompanyUser::where('company_id', $company->id)->first();
            if (!$companyUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user associated with this company',
                ], 404);
            }

            $userAdmin = $companyUser->user;
            if (!$userAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user associated with this company',
                ], 404);
            }

       
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'licence_issue_date' => 'nullable|string|max:255',
                'working_date' => 'nullable|string|max:255',
                'reg_number' => 'nullable|string|max:255',
                'full_address' => 'nullable|string|max:255',
                'email_address' => 'nullable|string|email|max:255',
                'website' => 'nullable|string|max:255',
                'fax' => 'nullable|string|max:255',
                'logo' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'palika_name' => 'nullable|string|max:255',
                'ward_number' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'contact_person_position' => 'nullable|string|max:255',
                'agreement_holder_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'license_number' => 'nullable|string|max:255',
                'activation_key' => 'nullable|string|max:255',
                'url_link' => 'nullable|string|max:255',
                'admin_name' => 'sometimes|required|string|max:255',
                'admin_email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $userAdmin->id,
                'password' => 'sometimes|required|string|min:6',
            ]);

           
            $company->update($validated);

         
            $userUpdates = [];
            $newToken = null;
            if ($request->has('admin_name')) {
                $userUpdates['name'] = $validated['admin_name'];
            }
            if ($request->has('admin_email')) {
                $userUpdates['email'] = $validated['admin_email'];
            }
            if ($request->has('password')) {
                $userUpdates['password'] = Hash::make($validated['password']);
              
                $userAdmin->tokens()->where('abilities', '["company_admin"]')->delete();
               
                $newToken = $userAdmin->createToken('MatraErpToken', ['company_admin'])->plainTextToken;
            }
            if (!empty($userUpdates)) {
                $userAdmin->update($userUpdates);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company and admin details updated successfully',
                'data' => [
                    'company' => $company->fresh(),
                    'user' => $userAdmin->fresh(),
                    'new_token' => $newToken,
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
    // Show a single resource
    public function show(Company $post): JsonResponse
    {
        return response()->json($post);
    }

    // Update a resource
    /**
 * Update the specified company in storage.
 */


    public function destroy(Company $post): JsonResponse
    {
        $post->delete();

        return response()->json(['message' => 'Company deleted!!']);
    }
}
