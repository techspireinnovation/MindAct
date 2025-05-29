<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $resources = ['branches'];
        $actions = ['store', 'show', 'update', 'index', 'destroy', 'print', 'export'];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$resource}.{$action}", 'guard_name' => 'api']);
            }
        }
    }
}
