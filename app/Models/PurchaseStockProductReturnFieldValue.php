<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class PurchaseStockProductReturnFieldValue extends BaseTenantModel
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_stock_product_return_id',
        'purchase_stock_product_id',
        'stock_product_id',
        'purchase_product_id',
        'stock_adjustment_id',
        'stock_transfer_id',
        'product_field_id',
        'company_id',
        'branch_id',
        'stock_reconciliation_id',
        'value',
        'product_id',

        'quantity_index',
        'quantity_type'
    ];

    protected $dates = ['deleted_at'];

    public function purchaseStockProductReturn()
    {
        return $this->belongsTo(PurchaseStockProductReturn::class, 'purchase_stock_product_return_id');
    }

    public function stockProduct()
    {
        return $this->belongsTo(StockProductDetails::class, 'stock_product_id');
    }

    //    public function purchaseProduct()
    //    {
    //        return $this->belongsTo(PurchaseProduct::class, 'purchase_product_id');
    //    }

    public function purchaseStockProduct()
    {
        return $this->belongsTo(PurchaseStockProduct::class, 'purchase_stock_product_id');
    }

    public function reconciliationProduct()
    {
        return $this->belongsTo(StockReconciliationDetail::class, 'stock_reconciliation_id');
    }

    public function adjustmentProduct()
    {
        return $this->belongsTo(StockAdjustmentProduct::class, 'stock_adjustment_id');
    }

    public function productField()
    {
        return $this->belongsTo(ProductField::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = Request::input('company_id');
            }
        });
    }

}
