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
    public function index(Request $request): JsonResponse
{
    try {
        
        $companyLink = $request->user()->company;
        if (! $companyLink) {
            return response()->json([
                'success' => false,
                'message' => 'User is not linked to any company.',
            ], 403);
        }
        $companyId = $companyLink->company_id;

       
        $staff = User::role('company_staff')
            ->whereHas('company', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->get();

       
        return response()->json([
            'success' => true,
            'message' => 'Staff list retrieved successfully.',
            'data'    => $staff,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'An error occurred while retrieving the staff list.',
            'error'   => $e->getMessage(),
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

  public function update(Request $request, $id): JsonResponse
{
    try {
        DB::beginTransaction();

      
        $staff = User::findOrFail($id);

      
        $validator = Validator::make($request->all(), [
            'name'     => 'sometimes|required|string|max:255',
            'email'    => 'sometimes|required|email|unique:users,email,' . $staff->id,
            'password' => 'sometimes|required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

     
        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        // 4) Update user fields
        $staff->update($validated);

        // 5) Ensure the 'company_staff' role is present
        $role = Role::firstOrCreate([
            'name'       => 'company_staff',
            'guard_name' => 'api',
        ]);
        if (! $staff->hasRole('company_staff')) {
            $staff->assignRole($role);
        }

        // 6) Confirm authenticated user + company context
        if (! $request->user() || ! $request->user()->company) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Authenticated user or company context not found',
            ], 403);
        }
        $companyId = $request->user()->company->company_id;

        // 7) Ensure CompanyUser pivot exists (won't duplicate)
        CompanyUser::firstOrCreate([
            'company_id' => $companyId,
            'user_id'    => $staff->id,
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Staff updated successfully',
            'data'    => $staff->fresh(),
        ], 200);

    } catch (ModelNotFoundException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Staff not found.',
            'error'   => $e->getMessage(),
        ], 404);

    } catch (QueryException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Database error occurred while updating the staff.',
            'error'   => $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred while updating the staff.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}

public function destroy():JsonResponse
{
    try {
        $staff = User::findOrFail($id);
        $staff->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff deleted successfully.',
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Staff not found.',
            'error' => $e->getMessage(),
        ], 404);
    } catch (QueryException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Database error occurred while deleting the staff.',
            'error' => $e->getMessage(),
        ], 500);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred while deleting the staff.',
            'error' => $e->getMessage(),
        ], 500);
    }


}

public function show($id): JsonResponse
{
    try {
        $staff = User::findOrFail($id);
        return response()->json([
            'success' => true,
            'message' => 'Staff retrieved successfully.',
            'data' => $staff,
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Staff not found.',
            'error' => $e->getMessage(),
        ], 404);
    } catch (QueryException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Database error occurred while retrieving the staff.',
            'error' => $e->getMessage(),
        ], 500);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred while retrieving the staff.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

}