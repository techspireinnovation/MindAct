<?php

namespace App\Models;

use App\Models\PaymentVoucher;
use App\Models\Scopes\CompanyIdScope;
use App\Observers\PaymentVoucherDetailObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class PaymentVoucherDetail extends BaseTenantModel
{
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'company_id',
        'customer_id',
        'payment_voucher_id',
        'party_name',
        'amount',
        'contra_account',
        'remarks',
        'cheque_slip',
        'remaining_balance'
    ];

    protected static function booted()
    {
        self::observe(PaymentVoucherDetailObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function receiptVoucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
