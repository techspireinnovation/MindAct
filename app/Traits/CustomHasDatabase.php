<?php

namespace App\Traits;

use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Events\DatabaseDeleted;

trait CustomHasDatabase
{
   public function database(): \Stancl\Tenancy\DatabaseConfig
{
    return new \Stancl\Tenancy\DatabaseConfig($this);
}


    protected static function bootCustomHasDatabase()
    {
        static::creating(function ($tenant) {
            // ✅ Only set database if not already provided
            if (empty($tenant->database)) {
                $tenant->database = 'tenant_' . uniqid();
            }
        });

        static::created(function ($tenant) {
            // ✅ Manually trigger the database creation event
            event(new DatabaseCreated($tenant));
        });

        static::deleting(function ($tenant) {
            // ✅ Manually trigger the database deletion event
            event(new DatabaseDeleted($tenant));
        });
    }

    // ✅ Override Stancl’s default getter/setter for database name storage
    public function getTenantDatabaseName(): string
    {
        return $this->database;
    }

    public function setTenantDatabaseName(string $name): void
    {
        $this->database = $name;
    }
}
