<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


use Illuminate\Database\Eloquent\Factories\HasFactory;


class Party extends BaseTenantModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'billing_address',
        'opening_balance',
        'district',
        'vdc_municipality',
        'pan_number',
        'type',
        'address',
        'phone',
        'email',
        'contact_person',
        'contact_person_phone',
        'country',
        'state',
        'city',
        'area',
        'bank_id',
        'bank_account_number',
        'balance_type',
        'is_active'
    ];


    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'party_id', 'id');
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'party_id', 'id');
    }
    public function purchasesUse()
    {
        return $this->hasMany(Purchase::class, 'party_id');
    }

    public function salesUse()
    {
        return $this->hasMany(Sale::class, 'party_id');
    }
    public function purchaseProductsUse()
    {
        return $this->hasMany(PurchaseProduct::class, 'party_id');
    }

    public function paymentVoucherDetails()
    {
        return $this->hasMany(PaymentVoucherDetail::class, 'party_id');
    }

    public function stocks()
    {
        return $this->belongsTo(Stock::class, 'party_id')->whereNull('deleted_at');
    }

}
