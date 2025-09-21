<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use App\Observers\CustomerObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'ledger_type',
        'address',
        'phone',
        'email',
        'contact_person',
        'contact_person_phone',
        'country',
        'state',
        'district',
        'vdc_municipality',
        'ward_no',
        'area',
        'city',
        'bank_name',
        'bank_id',
        'bank_account_number',
        'is_active'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        // self::observe(CustomerObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'customer_id', 'id');
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'customer_id', 'id');
    }
    public function purchasesUse()
    {
        return $this->hasMany(Purchase::class, 'customer_id');
    }

    public function salesUse()
    {
        return $this->hasMany(Sale::class, 'customer_id');
    }
    public function purchaseProductsUse()
    {
        return $this->hasMany(PurchaseProduct::class, 'customer_id');
    }

    public function paymentVoucherDetails()
    {
        return $this->hasMany(PaymentVoucherDetail::class, 'customer_id');
    }

}
