<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use App\Observers\JournalVoucherTransactionObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Request;

class JournalVoucherTransaction extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'journal_voucher_id',
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
        self::observe(JournalVoucherTransactionObserver::class);
        static::addGlobalScope(new CompanyIdScope());
        static::creating(function ($model) {
            // Only set if not already set
            if (empty($model->company_id)) {
                // Get the header value, fallback to 'US'
                $headerValue = Request::input('company_id');
                $model->company_id = $headerValue;
            }
        });
    }

    public function mainGroup()
    {
        return $this->hasOne(MainGroup::class, 'id', 'main_group_id');
    }

    public function accountGroup()
    {
        return $this->hasOne(AccountGroup::class, 'id', 'account_group_id');
    }

    public function accountHead()
    {
        return $this->hasOne(AccountHead::class, 'id', 'account_head_id');
    }


    public function subGroup()
    {
        return $this->hasOne(SubGroup::class, 'id', 'sub_group_id');
    }

    public function journalVoucher()
    {
        return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id', 'id');
    }
}
