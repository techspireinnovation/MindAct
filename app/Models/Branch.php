<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'deleted_at',
        'company_id',
    ];
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
