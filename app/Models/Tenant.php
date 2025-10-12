<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class Tenant extends BaseTenant
{
    use HasDomains;

    protected $fillable = [
        'id',
        'company_id',   // optional: link tenant to your Company model
        'data',         // JSON column for custom info (license, plan, etc.)
    ];

    public function company()
{
    return $this->belongsTo(Company::class);
}

}
