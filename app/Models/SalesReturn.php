<?php

namespace App\Models;
<<<<<<< HEAD
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
=======
use App\Models\Location;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
>>>>>>> 0053a2b161c3fb291b3f7f8e9939c3557dcdab93
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    
    protected $fillable = [
        'company_id',
<<<<<<< HEAD
        'sale_rate_type',
        'return_invoice_number',
        'customer_id',
        'batch_no',
       
        'tpin_number',
        'sales_id',
        'store_id',
        'location_id',
        'discount_amount',
        'discount_vat',
        'paid_amount',
        'round_of_amount',
        'payment_type',
        'sales_details',
        'terms',
        'is_active'
    ];
=======
        'customer_id', 
        'salesman_id', 
        'document_number', 
        'invoice_number',  
        'batch_no',  
        'balance',  
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
    
>>>>>>> 0053a2b161c3fb291b3f7f8e9939c3557dcdab93

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
<<<<<<< HEAD
=======


    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function salesReturnProducts(): HasMany
    {
        return $this->hasMany(SalesReturnProduct::class);
    }
>>>>>>> 0053a2b161c3fb291b3f7f8e9939c3557dcdab93
}
