<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoucherInnerDetail extends BaseTenantModel
{
    use SoftDeletes;
    protected $fillable = [
        'company_id',
        'voucher_summary_id',
        'particulars',
        'debit',
        'credit',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function voucherSummary()
    {
        return $this->belongsTo(VoucherSummary::class, 'voucher_summary_id');

    }
    public function voucherSummaryDetail()
    {
        return $this->belongsTo(VoucherSummaryDetail::class, 'voucher_summary_detail_id');

    }
}
