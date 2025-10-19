<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Request;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockReceiveFieldValue extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $table = 'stock_receive_field_values';

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_field_id',
        'product_id',
        'purchase_stock_product_id',
        'stock_adjustment_id',
        'stock_reconciliation_id',
        'purchase_product_id',
        'stock_product_id',
        'quantity_index',
        'quantity_type',
        'value',
        'deleted_at',
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

    public function purchaseStockProduct()
    {
        return $this->belongsTo(PurchaseStockProduct::class, 'purchase_stock_product_id');
    }


    public function stockProduct()
    {
        return $this->belongsTo(StockEntry::class, 'stock_product_id');
    }
}
