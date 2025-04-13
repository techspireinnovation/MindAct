<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
class SubGroup extends Model
{
    use softDeletes;

    protected $fillable=[
        'name',
        'company_id',
        'main_group_id',
        'code',
        'ranking_for_trial',
        'is_active',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
