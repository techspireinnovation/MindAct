<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Supplier extends BaseTenantModel
{
    use softDeletes;
    protected $fillable = [
        'name',
        'company_id',
        'email',
        'code',
        'mobile',
        'address',
        'pan_vat_number',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
