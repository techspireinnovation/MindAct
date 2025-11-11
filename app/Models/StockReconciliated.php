<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Helpers\Helper;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\SoftDeletes;
use Pratiksh\Nepalidate\Services\NepaliDate;
use Request;

class StockReconciliated extends BaseTenantModel
{
    protected $fillable = [
        'stock_reconciliation_id',
        'purchase_stock_product_id',
        'stock_adjustment_id',
        'purchase_type',
        'company_id',
        'branch_id',
        'mfd',
        'purchase_product_id',
        'reconciliated_type',
        'stock_product_id',
        'purchase_id',
        'product_id',
        'product_name',
        'product_code',
        'expiry_date',
        'quantity',
        'diff_stock',
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

    use SoftDeletes;
    protected $dates = ['deleted_at', 'created_at_bs'];
    protected $appends = ['created_at_bs'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
        static::creating(function ($model) {
            // Only set if not already set
            if (empty($model->company_id)) {
                // Get the header value, fallback to 'US'
                $headerValue = Request::input('company_id');
                $model->company_id = $headerValue;
            }
        });
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
    }

    public function fieldValues()
    {
        return $this->hasMany(PurchaseProductFieldValue::class, 'purchase_product_id');
    }




}
