<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use App\Observers\BankObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bank extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_primary',
        'is_active',
        'deleted_at',
        'company_id',
        'address',
        'class',
        'number',
        'swift',
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        self::observe(BankObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }
}
