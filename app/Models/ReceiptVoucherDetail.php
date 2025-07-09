<?php

namespace App\Models;

use App\Models\ReceiptVoucher;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ReceiptVoucherDetail extends Model
{
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'company_id',
        'customer_id',
        'receipt_voucher_id',
        'party_name',
        'amount',
        'contra_acount',
        'remarks',
        'cheque_slip',
        'remaining_balance'
    ];


    protected static function booted()
    {
        self::observe(ReceiptVoucherDetail::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function receiptVoucher()
    {
        return $this->belongsTo(ReceiptVoucher::class, 'receipt_voucher_id');
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
