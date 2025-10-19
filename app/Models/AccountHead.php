<?php

namespace App\Models;


use App\Models\AccountGroup;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\softDeletes;

class AccountHead extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];

    protected $fillable = [
        'name',
        'company_id',
        'account_group_id',
        'code',
        'is_primary',
        'is_active',
        'deleted_at'
    ];
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function accountGroup()
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }
    public function voucherSummaries()
    {
        return $this->hasMany(VoucherSummary::class, 'account_head_id');

    }

    public function voucherSummaryDetails()
    {
        return $this->hasMany(VoucherSummaryDetail::class, 'account_head_id');

    }
    public function journalVoucherTransactions(){
        return $this->hasMany(JournalVoucherTransaction::class,'account_head_id');
    }
}
