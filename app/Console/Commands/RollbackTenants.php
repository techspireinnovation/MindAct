<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RollbackTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rollback-tenants {--step= : Number of migrations to rollback per tenant}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback tenant-specific migrations for all tenant databases safely';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting tenant rollbacks...');

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            try {
                $tenantData = json_decode($tenant->data, true);
                $databaseName = $tenantData['database'] ?? $tenant->database;

                if (!$databaseName) {
                    $this->warn("Tenant {$tenant->id} has no database configured. Skipping.");
                    continue;
                }

                $this->info("Rolling back tenant: {$tenant->id} ({$databaseName})");

                // Ensure the database exists
                $dbExists = DB::connection('mysql')->selectOne(
                    "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?",
                    [$databaseName]
                );

                if (!$dbExists) {
                    $this->warn("Database {$databaseName} does not exist. Skipping tenant.");
                    continue;
                }

                // Switch connection to tenant
                config(['database.connections.tenant.database' => $databaseName]);
                DB::purge('tenant');
                DB::reconnect('tenant');

                // Optional safety check
                if (!Schema::connection('tenant')->hasTable('migrations')) {
                    $this->warn("No migrations table found for tenant {$tenant->id}. Skipping rollback.");
                    continue;
                }

                // Run rollback for tenant migrations
                $options = [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ];

                if ($this->option('step')) {
                    $options['--step'] = $this->option('step');
                }

                Artisan::call('migrate:rollback', $options);

                $this->info(Artisan::output());

            } catch (\Exception $e) {
                $this->error("Failed rolling back tenant {$tenant->id}: " . $e->getMessage());
                Log::error("Tenant rollback error", ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info('All tenant rollbacks completed (errors logged if any).');
    }
}
