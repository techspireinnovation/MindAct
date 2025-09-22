<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMasterUserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\CompanyUser;
use App\Models\Branch;
use Spatie\Permission\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;



class MasterUserController extends Controller
{

    public function store(StoreMasterUserRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $master = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $role = Role::firstOrCreate(['name' => 'master_user', 'guard_name' => 'api']);
            $master->assignRole($role);

            $companyIds = CompanyUser::whereIn('user_id', $data['company_admin_ids'])
                ->distinct()
                ->pluck('company_id');

            foreach ($companyIds as $companyId) {
                CompanyUser::firstOrCreate([
                    'user_id' => $master->id,
                    'company_id' => $companyId,
                ]);

                $branchIds = Branch::where('company_id', $companyId)->pluck('id');
                $master->branches()->syncWithoutDetaching($branchIds);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master user created.',
                'data' => [
                    'id' => $master->id,
                    'name' => $master->name,
                    'email' => $master->email,
                    'created_at' => $master->created_at,////
                    'updated_at' => $master->updated_at,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create master user.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
    protected function companiesWithBranches(int $masterUserId)
    {

        $companies = \App\Models\Company::query()
            ->select('companies.*')
            ->join('company_users as ca', 'ca.company_id', '=', 'companies.id')
            ->join('company_users as mu', 'mu.company_id', '=', 'companies.id')
            ->whereIn('ca.user_id', function ($q) use ($masterUserId) {

                $q->select('company_admin_ids')
                    ->from('master_user_company_admin_map')
                    ->where('master_user_id', $masterUserId);
            })
            ->where('mu.user_id', $masterUserId)
            ->distinct()
            ->get();

        $companies->load('branches:id,company_id,name');

        return $companies->map(function ($company) {
            return [
                'id' => $company->id,
                'name' => $company->name,
                'branches' => $company->branches->map(fn($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                ]),
            ];
        });
    }


    // public function index(Request $request)
    // {
    //     try {
    //         $masters = User::role('master_user')
    //             ->select('id', 'name', 'email', 'created_at')
    //             ->get()
    //             ->map(function ($master) {
    //                 $admins = User::role('company_admin')
    //                     ->whereHas('companies', fn($q) => $q->whereIn(
    //                         'companies.id',
    //                         $master->companies()->pluck('companies.id')
    //                     ))
    //                     ->get(['id', 'name', 'email']);

    //                 return [
    //                     'id' => $master->id,
    //                     'name' => $master->name,
    //                     'email' => $master->email,

    //                 ];
    //             });

    //         return response()->json([
    //             'success' => true,
    //             'data' => $masters
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to retrieve master users.',
    //             'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
    //         ], 500);
    //     }
    // }

public function index(Request $request)
{
    try {
        // base query for master users, eager-load companies to avoid N+1
        $query = User::role('master_user')
            ->select('id', 'name', 'email', 'created_at')
            ->with('companies:id'); // only load id from companies

        // 🔍 search by name or email
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', '%' . $search . '%')
                  ->orWhere('email', 'LIKE', '%' . $search . '%');
            });
        }

        $masters = $query->get()->map(function ($master) {
            $companyIds = $master->companies->pluck('id')->toArray();

            if (empty($companyIds)) {
                $admins = collect();
            } else {
                $admins = User::role('company_admin')
                    ->whereHas('companies', function ($q) use ($companyIds) {
                        $q->whereIn('companies.id', $companyIds);
                    })
                    ->get(['id', 'name', 'email']);
            }

            return [
                'name'  => $master->name,
                'email' => $master->email,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $masters
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve master users.',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
}



    public function show($id)
    {
        try {
            $master = User::role('master_user')->find($id);

            if (!$master || $master->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Master user not found or has been deleted.',
                ], 404);
            }

            $admins = User::role('company_admin')
                ->whereHas('companies', fn($q) => $q->whereIn(
                    'companies.id',
                    $master->companies()->pluck('companies.id')
                ))
                ->whereNull('deleted_at')
                ->get(['id', 'name']);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $master->id,
                    'name' => $master->name,
                    'email' => $master->email,
                    'company_admins' => $admins->map(fn($admin) => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                    ]),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve master user.',
            ], 500);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            $user = User::role('master_user')->find($id);

            if (!$user || $user->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Master user not found or has been deleted.',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'company_admin_ids' => 'sometimes|array',
                'company_admin_ids.*' => 'exists:users,id',
            ]);

            DB::beginTransaction();

            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }
            $user->save();

            if (isset($validated['company_admin_ids'])) {
                // Clear existing company and branch associations
                CompanyUser::where('user_id', $user->id)->delete();
                $user->branches()->detach();

                // Get company IDs from company_admin_ids
                $companyIds = CompanyUser::whereIn('user_id', $validated['company_admin_ids'])
                    ->distinct()
                    ->pluck('company_id');

                // Assign companies and branches to the master user
                foreach ($companyIds as $companyId) {
                    CompanyUser::firstOrCreate([
                        'user_id' => $user->id,
                        'company_id' => $companyId,
                    ]);

                    $branchIds = Branch::where('company_id', $companyId)->pluck('id');
                    $user->branches()->syncWithoutDetaching($branchIds);
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Master user updated.',
                'data' => $user->load('companies.branches'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update master user.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    public function destroy($id)
    {
        try {
            $user = User::role('master_user')->find($id);

            if (!$user || $user->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Master user not found or has been deleted.',
                ], 404);
            }

            DB::beginTransaction();
            CompanyUser::where('user_id', $user->id)->delete();
            $user->branches()->detach();

            $user->delete();

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Master user deleted.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete master user.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }



    

    
}