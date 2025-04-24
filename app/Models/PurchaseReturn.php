<?php

namespace App\Models;


use App\Models\Location;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'customer_id',
        'company_id',
        'ref_bill_number',
        'discount_after_vat',
        'purchase_bill_number',
        'deleted_at',
        'balance',
        'invoice_date',
        'remarks',
        'store_id',
        'location_id',
        'discount_amount',
        'roundoff_amount',
        'excise_duty',
        'health_insurance',
        'freight_amount',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function purchaseReturnProducts(): HasMany
    {
        return $this->hasMany(PurchaseProductReturn::class);
    }


}
