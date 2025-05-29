<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturnProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_vatable' => 'boolean',
        'expiry_date' => 'date',
        'mfd' => 'date',
    ];

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        'sales_return_id',
        'sale_product_id',
        'purchase_product_id',
        'product_id',
        'product_code',
        'product_name',
        'expiry_date',
        'mfd',
        'quantity',
        'free_quantity',
        'price',
        'discount_percent',
        'discount_amount',
        'batch_no',
        'is_vatable',
        'measure_unit_id',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    // Relationships

    public function saleReturn()
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function saleProduct()
    {
        return $this->belongsTo(SaleProduct::class, 'sale_product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
    }

    public function fieldValues()
    {
        return $this->hasMany(SaleReturnProductFieldValue::class, 'sale_return_product_id');
    }
}
