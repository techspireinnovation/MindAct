<?php

namespace App\Models;


use App\Models\MainGroup;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubGroup extends Model
{
    use softDeletes, HasFactory;
    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];

    protected $fillable = [
        'name',
        'is_primary',
        'company_id',
        'main_group_id',
        'code',
        'ranking_for_trial',
        'is_active',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function mainGroup()
    {
        return $this->belongsTo(MainGroup::class, 'main_group_id');

    }



    public function accountGroups(): HasMany
    {
        return $this->hasMany(AccountGroup::class, 'sub_group_id');
    }
    public function journalVoucherTransactions(): HasMany
    {
        return $this->hasMany(JournalVoucherTransaction::class, 'sub_group_id');
    }

}
