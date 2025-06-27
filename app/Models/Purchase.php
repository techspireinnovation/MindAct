<?php

namespace App\Models;


use App\Models\Location;
use App\Models\Scopes\CompanyIdScope;
use App\Observers\PurchaseObserver;
use App\Traits\ConvertsAdToBsDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes, HasFactory, ConvertsAdToBsDate;

    protected $casts = [
        'payment' => 'array',
    ];

    protected $fillable = [
        'customer_id',
        'customer_name',
        'pan_number',
        'company_id',
        'bank_id',
        'address',
        'customer_contact',
        'ref_bill_number',
        'document_number',
        'discount_after_vat',
        'purchase_bill_number',
        'deleted_at',
        'balance',
        'invoice_date',
        'invoice_date_bs',
        'batch_no',
        'payment',
        'remarks',
        'store_id',
        'location_id',
        'discount_type',
        'discount_value',
        'sub_total_before_discount',
        'taxable_amount',
        'non_taxable_amount',
        'roundoff_amount',
        'roundoff_type',
        'total_amount',
        'excise_duty',
        'vat_percent',
        'health_insurance',
        'freight_amount',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        self::observe(PurchaseObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_id');
    }

    public function purchaseProducts(): HasMany
    {
        return $this->hasMany(PurchaseProduct::class);
    }

    public function getPurchaseProductQuantityAttribute(): int
    {
        return PurchaseProduct::where('purchase_id', $this->id)->sum('quantity') ?? 0;
    }

    public function getPurchaseReturnAmountAttribute()
    {
        return PurchaseReturn::where('purchase_id', $this->id)->sum('sub_total_before_discount') ?? 0;
    }

    public function getPurchaseReturnDiscountAmountAttribute()
    {
        return PurchaseReturn::where('purchase_id', $this->id)->sum('discount_value') ?? 0;
    }

}
