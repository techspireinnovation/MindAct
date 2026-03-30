<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\PurchaseMasterKey;
use App\Models\SalesMasterKey;
use App\Models\ProductType;
use App\Stubs\MainGroupStub;
use App\Models\MeasureUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class SetupTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tenant;
    public $databaseName;
    public $validated;
    public $company;

    public $tries = 3; // Retry 3 times
    public $timeout = 900; // 15 minutes

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
            Log::info("Starting SetupTenantJob", [
                'tenant_id' => $this->tenant->id,
                'database' => $this->databaseName,
            ]);

            // 1️⃣ Create tenant database if not exists
            $exists = DB::connection('mysql')->selectOne(
                "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?",
                [$this->databaseName]
            );

            if (!$exists) {
                DB::connection('mysql')->statement(
                    "CREATE DATABASE `$this->databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
                Log::info('Tenant database created', ['database' => $this->databaseName]);
            }

            // 2️⃣ Switch tenant connection
            config(['database.connections.tenant.database' => $this->databaseName]);
            DB::purge('tenant');
            DB::connection('tenant')->reconnect();
            Log::info('Connected to tenant database', ['database' => $this->databaseName]);
            app()->register(\App\Providers\AppServiceProvider::class);

            // 3️⃣ Run tenant migrations
            \Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
            Log::info('Tenant migrations completed', ['output' => \Artisan::output()]);

            // 4️⃣ Insert initial tenant data
            Branch::create([
                'name' => $this->validated['name'],

                'branch_type' => 'Main',
                'is_active' => true,
                'is_primary' => true,
            ]);

            PurchaseMasterKey::create(['excise_duty' => 1]);
            SalesMasterKey::create(['excise_duty' => 1]);

            ProductType::insert([
                ['name' => 'Inventory', 'delete_status' => 0, 'is_primary' => true],
                ['name' => 'Assets', 'delete_status' => 0, 'is_primary' => false],
                ['name' => 'Service', 'delete_status' => 0, 'is_primary' => false],
                ['name' => 'Raw Materials', 'delete_status' => 0, 'is_primary' => false],
            ]);

            FiscalYear::create([
                'year_en' => '2026-27',
                'year_np' => '2082-83',

                'status' => true,
            ]);


            MeasureUnit::create([
                'name' => 'Piece',
                'symbol' => 'Pcs',
                'quantity' => 1,

            ]);
            MainGroupStub::createMainGroups();
            Log::info('Chart of accounts seeded');


            Role::firstOrCreate([
                'name' => 'company_admin',
                'guard_name' => 'api',
            ]);

            Log::info('Tenant setup completed successfully', [
                'tenant_id' => $this->tenant->id,
                'execution_time' => microtime(true) - $start . ' seconds',
            ]);


        } catch (\Exception $e) {
            Log::error('Tenant setup failed', [
                'tenant_id' => $this->tenant->id,
                'database' => $this->databaseName,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $start . ' seconds',
            ]);

            // Cleanup partially created DB
            try {
                DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `$this->databaseName`");
                Log::info('Dropped tenant database due to failure', ['database' => $this->databaseName]);
            } catch (\Exception $dropError) {
                Log::error('Failed to drop tenant DB after failure', ['error' => $dropError->getMessage()]);
            }

            throw $e; // Fail job
        }
    }
}
