<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

class TenantInitializer
{
    public function initializeTenant(Tenant $tenant, string $databaseName): void
    {
        $migrationPath = database_path('migrations/tenant');

        // 1️⃣ Create database if it doesn't exist
        $exists = DB::connection('mysql')->selectOne(
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?",
            [$databaseName]
        );

        if (!$exists) {
            DB::connection('mysql')->statement(
                "CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            Log::info('Tenant database created', ['database' => $databaseName]);
        } else {
            Log::info('Tenant database already exists, skipping creation', ['database' => $databaseName]);
        }

        // 2️⃣ Switch connection
        config(['database.connections.tenant.database' => $databaseName]);
        DB::purge('tenant');
        DB::connection('tenant')->reconnect();

        // 3️⃣ Run migrations
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        Log::info('Tenant migrations completed', ['output' => Artisan::output()]);

        // 4️⃣ Verify tables
        $tables = ['branches', 'product_types', 'measure_units', 'purchase_master_keys', 'sales_master_keys'];
        foreach ($tables as $table) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                throw new \Exception("Table `$table` is missing after migration.");
            }
        }
    }

    public static function switchTo(Tenant $tenant): void
    {
        config(['database.connections.tenant.database' => $tenant->database]);
        DB::purge('tenant');
        DB::connection('tenant')->reconnect();
        Log::info('Switched to tenant DB', ['db' => $tenant->database]);
    }

    public function cleanup(string $databaseName): void
    {
        try {
            $exists = DB::connection('mysql')->selectOne(
                "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?",
                [$databaseName]
            );

            if ($exists) {
                DB::connection('mysql')->statement("DROP DATABASE `$databaseName`");
                Log::info('Tenant DB dropped after failure', ['db' => $databaseName]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to cleanup tenant DB', ['db' => $databaseName, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
