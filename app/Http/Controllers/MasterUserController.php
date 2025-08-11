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

class MasterUserController extends Controller
{
    /* ------------------------------------------------------------
     *  1. CREATE
     * ------------------------------------------------------------ */
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
                'data' => $master->load('companies.branches'),
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

    public function index(Request $request)
    {
        $masters = User::role('master_user')
            ->select('id', 'name', 'email', 'created_at')
            ->get()
            ->map(function ($master) {
                $admins = User::role('company_admin')
                    ->whereHas('companies', fn($q) => $q->whereIn(
                        'companies.id',
                        $master->companies()->pluck('companies.id')
                    ))
                    ->with([
                        'companies:id,name',
                        'companies.branches:id,name,company_id'
                    ])
                    ->get(['id', 'name', 'email']);

                return [
                    'id' => $master->id,
                    'name' => $master->name,
                    'email' => $master->email,
                    'created_at' => $master->created_at,
                    'company_admins' => $admins,
                ];
            });

        return response()->json(['success' => true, 'data' => $masters]);
    }

    public function show($id)
    {
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
            ->with([
                'companies:id,name',
                'companies.branches:id,name,company_id'
            ])
            ->get(['id', 'name', 'email']);
    
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $master->id,
                'name' => $master->name,
                'email' => $master->email,
                'created_at' => $master->created_at,
                'company_admins' => $admins,
            ],
        ]);
    }
    /* ------------------------------------------------------------
     *  4. UPDATE
     * ------------------------------------------------------------ */
    public function update(Request $request, $id)
    {
        $user = User::role('master_user')->find($id);
    
        if (!$user || $user->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Master user not found or has been deleted.',
            ], 404);
        }
    
        $validated = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'email'               => 'sometimes|email|unique:users,email,' . $user->id,
            'password'            => 'sometimes|string|min:6',
            'company_admin_ids'   => 'sometimes|array',
            'company_admin_ids.*' => 'exists:users,id',
        ]);
    
        DB::beginTransaction();
        try {
            // basic fields
            if (isset($validated['name']))  $user->name  = $validated['name'];
            if (isset($validated['email'])) $user->email = $validated['email'];
            if (isset($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            $user->save();
    
            // re-link companies & branches
            if ($request->has('company_admin_ids')) {
                $companyIds = CompanyUser::whereIn('user_id', $validated['company_admin_ids'])
                                         ->distinct()
                                         ->pluck('company_id');
    
                // detach previous links
                CompanyUser::where('user_id', $user->id)->delete();
                $user->branches()->detach();
    
                foreach ($companyIds as $companyId) {
                    CompanyUser::firstOrCreate([
                        'user_id'    => $user->id,
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
                'data'    => $user->load('companies.branches'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Update failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /* ------------------------------------------------------------
     *  5. DELETE (soft)
     * ------------------------------------------------------------ */
    public function destroy($id)
    {
        $user = User::role('master_user')->findOrFail($id);

        DB::beginTransaction();
        try {
            CompanyUser::where('user_id', $user->id)->delete();
            $user->branches()->detach();

            $user->delete();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Master user deleted.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Delete failed', 'error' => $e->getMessage()], 500);
        }
    }
}