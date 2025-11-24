<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MigrateTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-tenants';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for all tenant databases safely';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting tenant migrations...');

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            try {
                $tenantData = json_decode($tenant->data ?? '{}', true) ?? [];
                $databaseName = $tenantData['database'] ?? $tenant->database;

                if (!$databaseName) {
                    $this->warn("Tenant {$tenant->id} has no database configured. Skipping.");
                    continue;
                }

                $this->info("Migrating tenant: {$tenant->id} ({$databaseName})");

                // Ensure DB exists
                $dbExists = DB::connection('mysql')->selectOne(
                    "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?",
                    [$databaseName]
                );

                if (!$dbExists) {
                    $this->warn("Database {$databaseName} does not exist. Skipping tenant.");
                    continue;
                }

                // Switch connection
                config(['database.connections.tenant.database' => $databaseName]);
                DB::purge('tenant');
                DB::reconnect('tenant');

                // Run tenant-specific migrations
                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                $this->info(Artisan::output());

            } catch (\Exception $e) {
                $this->error("Failed migrating tenant {$tenant->id}: " . $e->getMessage());
                Log::error("Tenant migration error", [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info('All tenant migrations completed.');
    }

}
