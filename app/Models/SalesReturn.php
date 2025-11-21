<?php

namespace App\Models;

use App\Models\Location;
use App\Models\Scopes\CompanyIdScope;
use App\Observers\SaleReturnObserver;
use App\Traits\ConvertsAdToBsDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturn extends BaseTenantModel
{
    use SoftDeletes, HasFactory, ConvertsAdToBsDate;

    protected $casts = [
        'payment' => 'array',
        'invoice_date' => 'date:Y-m-d',
        'invoice_date_bs' => 'date:Y-m-d',
    ];

    protected $appends = ['customer_name'];

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_id',
        'purchase_type',
        'salesman_id',
        'sale_id',
        'pan_number',
        'customer_name',
        'credit_days',
        'customer_address',
        'customer_contact',
        'return_bill_no',
        'ref_bill_no',
        'invoice_number',
        'sales_bill_number',
        'document_number',
        'batch_no',
        'balance',
        'invoice_date',
        'invoice_date_bs',
        'remarks',
        'store_id',
        'location_id',
        'sub_total_before_discount',
        'vat_amount',
        'discount',
        'non_taxable_amount',
        'taxable_amount',
        'excise_duty',
        'health_insurance',
        'freight_amount',
        'discount_after_vat',
        'total_amount',
        'round_of_amount',
        'roundoff_type',
        'payment',

    ];

    protected static function booted()
    {
        // self::observe(SaleReturnObserver::class);
        static::addGlobalScope(new CompanyIdScope());
    }

    public function getCustomerNameAttribute()
    {
        return $this->customer->party_name ?? null;
    }

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function salesReturnProducts(): HasMany
    {
        return $this->hasMany(SalesReturnProduct::class);
    }

    public function salesReturnAdditional(): HasMany
    {
        return $this->hasMany(SaleReturnAdditional::class, 'sales_return_id');
    }
}
