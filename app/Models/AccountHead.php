<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\softDeletes;

class AccountHead extends Model
{
    use softDeletes;

    protected $fillable=[
        'name',
        'company_id',
        'account_group_id',
        'code',
        'is_active',
        'deleted_at'
    ];
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
