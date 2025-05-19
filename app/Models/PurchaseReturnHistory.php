<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\CompanyIdScope;

class PurchaseReturnHistory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_return_id',
        'action',
        'data'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}