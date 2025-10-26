<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\softDeletes;

class Area extends BaseTenantModel
{

    use softDeletes, HasFactory;
    protected $fillable = [
        'name',
        'company_id',
        'is_active',
        'is_primary',
        'delete_status'

    ];
    protected $dates = ['deleted_at'];



    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
