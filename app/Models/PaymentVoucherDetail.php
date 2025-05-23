<?php

namespace App\Models;

use App\Models\PaymentVoucher;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;


class PaymentVoucherDetail extends Model
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
        static::addGlobalScope(new CompanyIdScope());
    }

    public function receiptVoucher()
    {
        return $this->belongsTo(PaymentVoucher::class,'receipt_voucher_id');
    }
    
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
