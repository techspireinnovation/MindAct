<?php

namespace App\Models;


use Request;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Model;

class HoldSaleProductFieldValue extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_field_id',
        'product_id',
        'hold_sale_product_id',
        'purchase_stock_product_id',
        'purchase_product_id',
        'stock_product_id',
        'stock_reconciliation_id',
        'stock_transfer_id',
        'stock_adjustment_id',
        'quantity_index',
        'value',
        'deleted_at',
        'quantity_type'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
        static::creating(function ($model) {
            // Only set if not already set
            if (empty($model->company_id)) {
                $headerValue = Request::input('company_id');
                $model->company_id = $headerValue;
            }
        });
    }

    public function productField()
    {
        return $this->belongsTo(ProductField::class, 'product_field_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function holdSaleProduct()
    {
        return $this->belongsTo(HoldSaleProduct::class, 'hold_sale_product_id');
    }
}
