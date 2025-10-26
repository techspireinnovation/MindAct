<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalVoucher extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'company_id',
        'project_id',
        'salesman_id',
        'date',
        'voucher_number',
        'reference_number',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function transactions()
    {
        return $this->hasMany(JournalVoucherTransaction::class, 'journal_voucher_id', 'id');
    }

    public function project()
    {
        return $this->hasOne(Project::class, 'id', 'project_id');
    }

    public function salesman()
    {

        return $this->hasOne(Salesman::class, 'id', 'salesman_id');
    }
}
