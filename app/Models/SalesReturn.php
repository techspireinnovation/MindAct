<?php

namespace App\Models;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
