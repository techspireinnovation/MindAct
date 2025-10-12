<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Location;
use App\Models\Scopes\CompanyIdScope;
use App\Observers\PurchaseReturnObserver;
use App\Traits\ConvertsAdToBsDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseStockReturn extends Model
{
    use SoftDeletes, HasFactory, ConvertsAdToBsDate;

    protected $casts = [
        'payment' => 'array'
    ];
    protected $fillable = [
        'purchase_id',
        'customer_id',
        'customer_name',
        'invoice_number',
        'customer_contact',
        'purchase_return_type',
        'pan_number',
        'address',
        'company_id',
        'branch_id',
        'return_bill_no',
        'ref_bill_no',
        'discount_after_vat',
        'purchase_bill_number',
        'expiry_date',
        'remarks',
        'reason',
        'batch_no',
        'purchase_number',
        'deleted_at',
        'balance',
        'invoice_date',
        'invoice_date_bs',
        'payment',
        'store_id',
        'discount_type',
        'discount_value',
        'sub_total_before_discount',
        'non_taxable_amount',
        'taxable_amount',
        'location_id',
        'discount_amount',
        'roundoff_amount',
        'roundoff_type',
        'total_amount',
        'vat_percent',
        'excise_duty',
        'health_insurance',
        'freight_amount',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        // self::observe(PurchaseReturnObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function purchaseStockProductReturns(): HasMany
    {
        return $this->hasMany(PurchaseStockProductReturn::class);
    }
    // public function purchaseStockReturn()
    // {
    //     return $this->belongsTo(PurchaseStockReturn::class);
    // }

    public function purchaseProduct()
    {
        return $this->belongsTo(PurchaseProduct::class);
    }

     public function purchaseDtockProduct()
    {
        return $this->belongsTo(PurchaseStockProduct::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(PurchaseStockProductReturnFieldValue::class, 'purchase_stock_product_return_id');
    }
    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

}
