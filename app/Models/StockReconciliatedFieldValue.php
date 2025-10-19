<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


use App\Helpers\Helper;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\SoftDeletes;
use Pratiksh\Nepalidate\Services\NepaliDate;
use Request;

class StockReconciliatedFieldValue extends BaseTenantModel
{
    use SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'stock_reconciliated_id',
        'stock_reconciliation_id',
        'company_id',
        'branch_id',
        'product_field_id',
        'product_id',
        'purchase_stock_product_id',
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

 

    public function stockReconciliatedProduct()
    {
        return $this->belongsTo(StockReconciliated::class, 'stock_reconciliated_id');
    }


    public function stockProduct()
    {
        return $this->belongsTo(StockEntry::class, 'stock_product_id');
    }

}
