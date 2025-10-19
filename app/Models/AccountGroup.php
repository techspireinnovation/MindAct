<?php

namespace App\Models;

use App\Models\MainGroup;
use App\Models\Scopes\CompanyIdScope;
use App\Models\SubGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountGroup extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];


    protected $fillable = [
        'name',
        'company_id',
        'main_group_id',
        'sub_group_id',
        'code',
        'is_primary',
        'is_active',
        'deleted_at'
    ];

    public function mainGroup()
    {
        return $this->belongsTO(MainGroup::class, 'main_group_id');
    }

    public function subGroup()
    {
        return $this->belongsTO(SubGroup::class, 'sub_group_id');
    }

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }


    public function accountHeads()
    {
        return $this->hasMany(AccountHead::class, 'account_group_id');
    }

    public function voucherSummaries()
    {
        return $this->hasMany(VoucherSummary::class, 'account_group_id');

    }
    public function fixedAssetGroup(){
        return $this->hasMany(FixedAssetGroup::class,'account_group_id');
    }

    public function journalVoucherTransactions(){
        return $this->hasMany(JournalVoucherTransaction::class,'account_group_id');
    }
    public function voucherSummaryDetails(){
        return $this->hasMany(VoucherSummaryDetail::class,'account_group_id');
    }
}
