<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use App\Observers\BankObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bank extends BaseTenantModel
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
        // self::observe(BankObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'bank_id');
    }
    public function sales()
    {
        return $this->hasMany(Sale::class, 'bank_id');
    }
    public function bankVouchers()
    {
        return $this->hasMany(BankVoucher::class, 'bank_id');
    }
    public function customers()
    {
        return $this->hasMany(Customer::class, 'bank_id');
    }


}
