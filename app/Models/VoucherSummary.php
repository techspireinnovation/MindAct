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
        'ref_bill_number',
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

    public function accountHead()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');

    }

    public function accountGroup()
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');

    }

    public function voucherSummaryDetail()
    {
        return $this->hasMany(VoucherSummaryDetail::class, 'voucher_summary_id');
    }

    public function voucherSummaryInnerDetail()
    {
        return $this->hasMany(VoucherInnerDetail::class, 'voucher_summary_id');

    }
}
