<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends BaseTenantModel
{
    use softDeletes,HasFactory;

    protected $fillable = [
        'reference_no',
        'invoice_date',
        'invoice_date_bs',
        'location_id',
        'branch_id',
        'remarks',
        'reasons',
        'company_id',
        'product_details',
        
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function StockAdjustmentProduct(): HasMany {
        return $this->hasMany(StockAdjustmentProduct::class, 'stock_adjustment_id');
    }
    public function stockProductDetailsUse(): HasMany {
        return $this->hasMany(StockProductDetails::class , 'stock_adjustment_id');
    }

}
