<?php

namespace Database\Seeders;

use App\Models\User;
use Hash;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;


class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'api',
        ]);

        $user = User::firstOrCreate([
            'name' => "Matra Admin",
            'email' => "superadmin@matraerp.com",
            'password' => Hash::make("password890"),
        ]);

        $user = User::whereName('Matra Admin')->first();
        $user->assignRole($role);

        // Create Company Admin
        Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'api']);
    }
}
