<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;

use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Bind the TenantInitializer service
        $this->app->singleton('tenancy.initializer', function ($app) {
            return new TenantInitializer();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

    }

}

/**
 * TenantInitializer class to handle tenant database creation and migrations.
 */
class TenantInitializer
{
    /**
     * Initialize a tenant by creating its database and running migrations.
     *
     * @param Tenant $tenant
     * @param string $databaseName
     * @return void
     * @throws \Exception
     */
    public function initializeTenant(Tenant $tenant, string $databaseName): void
    {
        $migrationPath = database_path('migrations/tenant');

        // Create tenant database
        if (!DB::connection('mysql')->selectOne("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?", [$databaseName])) {
            DB::connection('mysql')->statement("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            Log::info('Tenant database created', ['database' => $databaseName, 'tenant_id' => $tenant->id]);
        }

        // Set and connect to tenant database
        config(['database.connections.tenant.database' => $databaseName]);
        DB::purge('tenant');
        DB::connection('tenant')->reconnect();
        Log::info('Connected to tenant database', ['database' => $databaseName, 'tenant_id' => $tenant->id]);

        // Run migrations
        $migrationFiles = glob($migrationPath . '/*.php');
        Log::info('Migration files found', [
            'tenant_id' => $tenant->id,
            'path' => $migrationPath,
            'files' => $migrationFiles ?: 'None',
        ]);

        if (!is_dir($migrationPath) || empty($migrationFiles)) {
            Log::error('No migration files found or directory missing', ['path' => $migrationPath, 'tenant_id' => $tenant->id]);
            throw new \Exception('No migration files found in ' . $migrationPath);
        }

        \Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
        Log::info('Tenant migrations run', ['tenant_id' => $tenant->id, 'output' => \Artisan::output()]);

        // Verify required tables
        $requiredTables = ['branches', 'product_types', 'measure_units', 'purchase_master_keys', 'sales_master_keys'];
        foreach ($requiredTables as $table) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                Log::error("Table $table missing after migrations", ['tenant_id' => $tenant->id]);
                throw new \Exception("Table $table missing after migrations");
            }
        }
    }

    public static function switchTenant(Tenant $tenant)
    {
        $databaseName = $tenant->database;

        config(['database.connections.tenant.database' => $databaseName]);
        DB::purge('tenant');
        DB::connection('tenant')->reconnect();

        \Log::info("Tenant connection switched", ['database' => $databaseName]);
    }

    public function cleanupTenant(string $databaseName): void
    {
        try {
            // Purge tenant connection to avoid locks
            DB::purge('tenant');

            // Check if database exists
            $exists = DB::connection('mysql')->selectOne(
                "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?",
                [$databaseName]
            );

            if ($exists) {
                // Retry dropping the database up to 3 times
                $maxAttempts = 3;
                $attempt = 1;

                while ($attempt <= $maxAttempts) {
                    try {
                        DB::connection('mysql')->statement("SET FOREIGN_KEY_CHECKS = 0");
                        DB::connection('mysql')->statement("DROP DATABASE `$databaseName`");
                        DB::connection('mysql')->statement("SET FOREIGN_KEY_CHECKS = 1");
                        Log::info('Tenant database dropped', ['database' => $databaseName]);
                        break;
                    } catch (\Exception $e) {
                        Log::warning('Failed to drop tenant database', [
                            'database' => $databaseName,
                            'attempt' => $attempt,
                            'error' => $e->getMessage(),
                        ]);

                        if ($attempt === $maxAttempts) {
                            throw new \Exception("Failed to drop tenant database after $maxAttempts attempts: " . $e->getMessage());
                        }

                        sleep(1); // Wait before retrying
                        $attempt++;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to clean up tenant database', [
                'database' => $databaseName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    protected function deleteDatabaseDirectory(string $databaseName): void
    {
        try {
            $dataDir = config('database.connections.mysql.options.data_directory', 'C:\\ProgramData\\MySQL\\MySQL Server 8.0\\Data');
            $tenantDir = $dataDir . DIRECTORY_SEPARATOR . $databaseName;
            if (is_dir($tenantDir)) {
                $command = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? "rmdir /S /Q " . escapeshellarg($tenantDir)
                    : "rm -rf " . escapeshellarg($tenantDir);
                exec($command, $output, $returnVar);
                if ($returnVar !== 0) {
                    Log::warning('Failed to delete tenant database directory', [
                        'directory' => $tenantDir,
                        'output' => $output,
                    ]);
                } else {
                    Log::info('Tenant database directory deleted', ['directory' => $tenantDir]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete tenant database directory', [
                'directory' => $tenantDir,
                'error' => $e->getMessage(),
            ]);
        }
    }
}