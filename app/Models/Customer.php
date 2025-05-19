<?php

namespace App\Models;

use App\Models\Sale;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use SoftDeletes, HasFactory;
    
    protected $casts = [
        'is_active' => 'boolean'
    ];  
    protected $fillable = [
        'company_id',
        'party_name',
        'pan_number',
        'billing_address',
        'opening_balance',
        'district',
        'ledger_type',
        'address',
        'phone',
        'email',
        'contact_person',
        'contact_person_phone',
        'country',
        'state',
        'city',
        'area',
        'bank_name',
        'bank_account_number',                              
        'is_active'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
