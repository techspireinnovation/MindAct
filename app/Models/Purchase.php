<?php

namespace App\Models;


use App\Models\Brand;
use App\Models\Location;
use App\Models\MeasureUnit;
use App\Models\ProductCategory;
use App\Models\ProductList;
use App\Models\ProductType;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    protected $fillable = [
        'mrn_number',
        'company_id',
        'bill_number',
        'pan_vat_number',
        'deleted_at',
        'mrn_date',
        'bill_date',
        'supplier_id',
        'location_id',
        'discount_percent',
        'discount_percent_vat',
        'discount_amount_vat',
        'discount_amount',
        'roundoff_amount',
        'payment_type',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
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


    public function purchaseProducts(): HasMany
    {
        return $this->hasMany(ProductList::class);
    }


}
