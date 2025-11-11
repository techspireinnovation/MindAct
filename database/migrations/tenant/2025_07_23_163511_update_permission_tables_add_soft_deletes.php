<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        if (empty($tableNames)) {
            throw new \Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        /* ------------ Permissions ------------ */
        if (! Schema::connection('tenant')->hasColumn($tableNames['permissions'], 'deleted_at')) {
           Schema::connection('tenant')->table($tableNames['permissions'], function (Blueprint $table) {
                $table->softDeletes();
            });

            /* Re-create the old unique index so we can add the new one */
            $oldIndex = 'permissions_name_guard_name_unique';
            if ($this->indexExists($tableNames['permissions'], $oldIndex)) {
                Schema::connection('tenant')->table($tableNames['permissions'], function (Blueprint $table) use ($oldIndex) {
                    $table->dropUnique($oldIndex);
                });
            }

           Schema::connection('tenant')->table($tableNames['permissions'], function (Blueprint $table) {
                $table->unique(['name', 'guard_name', 'deleted_at'], 'permissions_name_guard_name_deleted_at_unique');
            });
        }

        /* ------------ Roles ------------ */
        if (! Schema::connection('tenant')->hasColumn($tableNames['roles'], 'deleted_at')) {
            Schema::connection('tenant')->table($tableNames['roles'], function (Blueprint $table) {
                $table->boolean('is_active')->default(1);
                $table->softDeletes();
            });

            /* Re-create the old unique index(es) */
            $rolesTable = $tableNames['roles'];
            $oldUnique = config('permission.teams')
                ? 'roles_team_foreign_key_name_guard_name_unique'
                : 'roles_name_guard_name_unique';

            if ($this->indexExists($rolesTable, $oldUnique)) {
                Schema::connection('tenant')->table($rolesTable, function (Blueprint $table) use ($oldUnique) {
                    $table->dropUnique($oldUnique);
                });
            }

            Schema::connection('tenant')->table($rolesTable, function (Blueprint $table) use ($columnNames) {
                $unique = config('permission.teams')
                    ? [$columnNames['team_foreign_key'], 'name', 'guard_name', 'deleted_at']
                    : ['name', 'guard_name', 'deleted_at'];

                $indexName = config('permission.teams')
                    ? 'roles_team_name_guard_name_deleted_at_unique'
                    : 'roles_name_guard_name_deleted_at_unique';

                $table->unique($unique, $indexName);
            });
        }
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        if (empty($tableNames)) {
            throw new \Exception('Error: config/permission.php not found.');
        }

        /* ------------ Roles ------------ */
        if (Schema::connection('tenant')->hasColumn($tableNames['roles'], 'deleted_at')) {
            $rolesTable = $tableNames['roles'];

            /* Drop the new unique index */
            $newUnique = config('permission.teams')
                ? 'roles_team_name_guard_name_deleted_at_unique'
                : 'roles_name_guard_name_deleted_at_unique';

            if ($this->indexExists($rolesTable, $newUnique)) {
                Schema::connection('tenant')->table($rolesTable, function (Blueprint $table) use ($newUnique) {
                    $table->dropUnique($newUnique);
                });
            }

            Schema::connection('tenant')->table($rolesTable, function (Blueprint $table) use ($columnNames) {
                $table->dropColumn('deleted_at');
                $table->dropColumn('is_active');

                /* Restore the old unique index */
                $oldUnique = config('permission.teams')
                    ? [$columnNames['team_foreign_key'], 'name', 'guard_name']
                    : ['name', 'guard_name'];

                $oldIndexName = config('permission.teams')
                    ? 'roles_team_foreign_key_name_guard_name_unique'
                    : 'roles_name_guard_name_unique';

                $table->unique($oldUnique, $oldIndexName);
            });
        }

        /* ------------ Permissions ------------ */
        if (Schema::connection('tenant')->hasColumn($tableNames['permissions'], 'deleted_at')) {
            $permsTable = $tableNames['permissions'];

            $newUnique = 'permissions_name_guard_name_deleted_at_unique';
            if ($this->indexExists($permsTable, $newUnique)) {
                Schema::connection('tenant')->table($permsTable, function (Blueprint $table) use ($newUnique) {
                    $table->dropUnique($newUnique);
                });
            }

            Schema::connection('tenant')->table($permsTable, function (Blueprint $table) {
                $table->dropColumn('deleted_at');
                $table->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
            });
        }
    }

    /* Helper to check if an index exists (MySQL / MariaDB) */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = config('database.default');
        $driver     = config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            return false; // skipping for SQLite tests
        }

        return collect(DB::select('SHOW INDEXES FROM '.$table))
            ->pluck('Key_name')
            ->contains($indexName);
    }
};