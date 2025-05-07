<?php

namespace App\Models;
use App\Models\Location;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'payment' => 'array'
    ];
    
    protected $fillable = [
        'company_id',
        'customer_id', 
        'salesman_id', 
        'document_number', 
        'invoice_number',  
        'batch_no',  
        'balance',
        'payment',  
        'invoice_date',  
        'remarks', 
        'store_id',
        'location_id',
        'discount_amount',
        'excise_duty', 
        'health_insurance',
        'freight_amount',  
        'discount_vat',
        'discount_after_vat',  
        'paid_amount',
        'round_of_amount',
        'payment_type',
        
    ];
    

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
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
}
