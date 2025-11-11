<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\PurchaseMasterKey;
use App\Models\SalesMasterKey;
use App\Models\ProductType;
use App\Models\MeasureUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InitializeTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tenant;
    protected $databaseName;
    protected $validated;
    protected $company;

    public function __construct($tenant, $databaseName, $validated, $company)
    {
        $this->tenant = $tenant;
        $this->databaseName = $databaseName;
        $this->validated = $validated;
        $this->company = $company;
    }

    public function handle()
    {
        $start = microtime(true);

        try {
            // Create tenant database
            DB::connection('mysql')->statement("CREATE DATABASE `$this->databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            Log::info('Tenant database created', ['database' => $this->databaseName, 'tenant_id' => $this->tenant->id]);

            // Load schema dump
            $this->tenant->run(function () {
                $schemaPath = database_path('tenant-schema.sql');
                if (file_exists($schemaPath)) {
                    DB::connection('tenant')->unprepared(file_get_contents($schemaPath));
                    Log::info('Tenant schema loaded from dump', ['tenant_id' => $this->tenant->id]);
                } else {
                    // Fallback to migrations if schema dump is missing
                    \Artisan::call('migrate', [
                        '--database' => 'tenant',
                        '--path' => 'database/migrations/tenant',
                        '--force' => true,
                    ]);
                    Log::info('Tenant migrations completed', ['tenant_id' => $this->tenant->id, 'output' => \Artisan::output()]);
                }
            });

            // Insert tenant-specific data
            $this->tenant->run(function () {
                Branch::create([
                    'name' => $this->validated['name'],
                    'company_id' => $this->company->id,
                    'branch_type' => 'Main',
                    'is_active' => true,
                    'is_primary' => true,
                ]);

                PurchaseMasterKey::create(['company_id' => $this->company->id]);
                SalesMasterKey::create(['company_id' => $this->company->id]);

                ProductType::insert([
                    ['name' => 'Inventory', 'delete_status' => 0, 'company_id' => $this->company->id],
                    ['name' => 'Assets', 'delete_status' => 0, 'company_id' => $this->company->id],
                    ['name' => 'Service', 'delete_status' => 0, 'company_id' => $this->company->id],
                    ['name' => 'Raw Materials', 'delete_status' => 0, 'company_id' => $this->company->id],
                ]);

                MeasureUnit::create([
                    'name' => 'Piece',
                    'symbol' => 'Pcs',
                    'quantity' => 1,
                    'company_id' => $this->company->id,
                ]);

                \Spatie\Permission\Models\Role::firstOrCreate([
                    'name' => 'company_admin',
                    'guard_name' => 'api',
                ]);

                Log::info('Tenant data setup completed', [
                    'tenant_id' => $this->tenant->id,
                   
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Failed to initialize tenant', [
                'tenant_id' => $this->tenant->id,
                'database' => $this->databaseName,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $start . ' seconds',
            ]);

            // Cleanup tenant database on failure
            try {
                DB::purge('tenant');
                DB::connection('mysql')->getPdo();
                DB::statement("DROP DATABASE IF EXISTS `$this->databaseName`");
                Log::info('Tenant database dropped due to initialization failure', ['database' => $this->databaseName]);
            } catch (\Exception $cleanupError) {
                Log::error('Failed to drop tenant database during initialization failure', [
                    'database' => $this->databaseName,
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            throw $e; // Re-throw to allow queue to handle retries
        }
    }
}
