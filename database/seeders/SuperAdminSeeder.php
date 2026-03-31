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
            'email' => "superadmin@mantraerp.com",
            'password' => Hash::make("password@890"),
        ]);
        $user = User::whereEmail('superadmin@mantraerp.com')->first();
        $user->assignRole($role);

       
        Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'api']);
    }
}
