<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pratiksh\Nepalidate\Services\NepaliDate;

class SaleProduct extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];

    protected $fillable = [
        'company_id',
        'sale_id',
        'product_id',
        'purchase_product_id',
        'expiry_date',
        'code',
        'name',
        'batch_no',
        'measure_unit_id',
        'quantity',
        'free_quantity',
        'price',
        'discount_percent',
        'discount_amount',
        'is_vatable',
    ];
    protected $dates = ['deleted_at'];
    protected $appends = ['created_at_bs'];
    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(SalesProductFieldValue::class, 'sale_product_id');
    }

    public function saleProductReturns()
    {
        return $this->hasMany(SalesReturnProduct::class, 'sale_product_id');
    }

    public function getSaleQuantityAttribute()
    {
        return self::where('product_id', $this->product_id)->sum('quantity') ?? 0;
    }

    public function getSaleRateAttribute()
    {
        return self::where('product_id', $this->product_id)->latest('id')->first()->price ?? 0;
    }

    public function getSaleDiscountAmountAttribute()
    {
        return self::where('product_id', $this->product_id)->latest('id')->first()->discount_amount ?? 0;
    }

    public function getSaleUnitAttribute()
    {
        $primary = self::where('product_id', $this->product_id)->latest('id')->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id);
        else
            return null;
    }
    public function getCreatedAtBsAttribute(): string
    {
        return NepaliDate::create($this->created_at)->toBS();
    }

}
