<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoucherSummary extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'date',
        'date_bs',
        'company_id',
        'branch_id',
        'voucher_number',
        'particulars',
        'debit',
        'credit',
        'account_head_id',
        'account_group_id',
        'payment_type',
        'cheque_number',
        'tr_bill_number',
        'type',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
