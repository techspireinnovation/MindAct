<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CompanyUser;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index() : JsonResponse {
        try {
            $staf = User::where('role', 'staff')
            ->where('company_id',$staff->company_id)
            ->get();
            return response()->json([
                'success' => true,
                'message' => 'Staff list retrieved successfully.',
                'data' => $staf
            ], 200);
        }catch(ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No staff found for this company.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the staff list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
  {
    try {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $validated['password'] = bcrypt($validated['password']);

        DB::beginTransaction();

        // Assuming User is the model for staff
        $staff = User::create($validated);

        // Assign role
        $role = Role::firstOrCreate([
            'name' => 'company_staff',
            'guard_name' => 'api',
        ]);
        $staff->assignRole($role);

        
        
        if (!$request->user() || !$request->user()->company) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Authenticated user or company ID not found',
            ], 403);
        }
        $userID = $request->user()->company;
        $userId = $userID->company_id;
        if (!$userId) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Company ID not found for the authenticated user.',
            ], 403);
        }

        $companyUser = new CompanyUser();
        $companyUser->company_id = $request->user()->company->company_id;
        $companyUser->user_id = $staff->id;
        $companyUser->save();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Staff created successfully',
            'data' => $staff,
        ], 201);

    }catch(ModelNotFoundException $e){
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Company not found.',
            'error' => $e->getMessage(),
        ], 404);
    }catch(QueryException $e){
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Database error occurred while creating the staff.',
            'error' => $e->getMessage(),
        ], 500);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'An error occurred while creating the staff.',
            'error' => $e->getMessage(),
        ], 500);
    }
  }

}
