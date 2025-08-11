<?php
// app/Http/Controllers/MasterUserController.php
namespace App\Http\Controllers;

use App\Http\Requests\StoreMasterUserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Models\CompanyUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class MasterUserController extends Controller
{
    public function store(StoreMasterUserRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $master = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $role = Role::firstOrCreate(['name' => 'master_user', 'guard_name' => 'api']);
            $master->assignRole($role);

            $companyIds = CompanyUser::whereIn('user_id', $data['company_admin_ids'])
                                     ->distinct()
                                     ->pluck('company_id');

            foreach ($companyIds as $companyId) {
                CompanyUser::firstOrCreate([
                    'user_id'    => $master->id,
                    'company_id' => $companyId,
                ]);

                $branchIds = Branch::where('company_id', $companyId)->pluck('id');
                $master->branches()->syncWithoutDetaching($branchIds);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master user created and linked to all existing branches.',
                'data'    => $master->load('companies.branches'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create master user.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}