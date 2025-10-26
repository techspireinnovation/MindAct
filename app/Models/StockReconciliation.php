<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockReconciliation extends BaseTenantModel
{
  use softDeletes,HasFactory;

   protected $casts = [
        'product_details' => 'array',
    ];

    protected $fillable = [

        'company_id',
        'branch_id',
        'date_ad',
        'date_bs',
        'reconciliation_no',
        'document_no',
        'branch_id',

        'remarks',


    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function stockReconciliationDetails(): HasMany {
        return $this->hasMany(StockReconciliationDetail::class , 'stock_reconciliation_id');
    }

}
