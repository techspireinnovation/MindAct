<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\softDeletes;

class ProductField extends Model
{
    use softDeletes;

    protected $fillable = [
        'name',
        'type',
        'values',
        'company_id',
        'is_active',
        'deleted_at'
    ];

    protected $casts = [
        'values' => 'array',
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
