<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalVoucherTransaction extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'jounal_voucher_id',
        'main_group_id',
        'account_group_id',
        'account_head_id',
        'sub_group_id',
        'account_code',
        'particulars',
        'type',
        'debit',
        'credit',
        'debit',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
