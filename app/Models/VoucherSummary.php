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
        'company_id',
        'voucher_number',
        'particulars',
        'debit',
        'account_head_id',
        'account_head_id',
        'account_head_id',
        'pan_vat_number',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
