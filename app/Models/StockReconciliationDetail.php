<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\MeasureUnit;
use App\Models\StockReconciliationFieldValue;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockReconciliationDetail extends Model
{
    use softDeletes, HasFactory;

    protected $casts = [

        'deleted_at' => 'datetime',
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_reconciliation_id',
        'purchase_stock_product_id',
        'mfd',
        'purchase_product_id',
        'stock_product_id',
        'purchase_id',
        'purchase_type',
        'product_id',
        'product_name',
        'product_code',
        'current_stock',
        'actual_stock',
        'diff_stock',
        'quantity',
        'free_quantity',
        'price',
        'mfd',
        'discount_percent',
        'discount_amount',
        'amount',
        'is_vatable',
        'measure_unit_id',

    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'unit_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(StockReconciliationFieldValue::class, 'stock_reconciliation_detail_id');
    }
}
