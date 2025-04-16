<?php

namespace App\Models;


use App\Models\Brand;
use App\Models\Location;
use App\Models\MeasureUnit;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\ProductList;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    protected $fillable = [
        'name',
        'debit_note',
        'credit_note',
        'is_active',
        'deleted_at',
        'company_id',
        'category_id',
        'brand_id',
        'measure_unit_id',
        'purchase_rate',
        'purchase_rate_vat',
        'retail_sales_price',
        'retail_sales_price_vat',
        'retail_sales_price_profit_percent',
        'wholesales_price',
        'wholesales_price_vat',
        'wholesales_price_profit_percent',
        'is_vatable',
        'product_type_id',
        'location_id',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function productFieldValues(): HasMany
    {
        return $this->hasMany(ProductFieldValue::class);
    }

    public function productLists(): HasMany
    {
        return $this->hasMany(ProductList::class);
    }


}
