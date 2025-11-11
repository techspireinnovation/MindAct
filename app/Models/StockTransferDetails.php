<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockTransferDetails extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [

        'deleted_at' => 'datetime',
    ];

    protected $fillable = [
        'company_id',
        'stock_transfer_id',
        'product_id',
        'product_name',
        'quantity',
        'unit',
        'batch_no',
        'price',
        'amount',
        'purchase_stock_product_id',
        'stock_adjustment_id',
        'stock_reconciliation_id',
        'transfer_status',
        'branch_id',
        'mfd',
        'purchase_type',
        'purchase_product_id',
        'stock_product_id',
        'purchase_id',
        'product_id',
        'product_name',
        'product_code',
        'expiry_date',
        'quantity',
        'deleted_at',
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
        return $this->hasMany(StockTransferFieldValue::class, 'stock_transfer_details_id');
    }


}
