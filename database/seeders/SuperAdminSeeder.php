<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;


class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user = User::find(1);
        $user->assignRole($role);

        // Create Company Admin
        Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'api']);
    }
}
