<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];


    protected $fillable = [
        'company_id',
        'store_id',
        'customer_id',
        'entry_type',
        'note',
        'invoice_quotation_number',
        'customer_name',
        'customer_phone',
        'bill_number',
        'tpin_number',
        'billing_date',
        'location',
        'sale_rate_type',
        'discount',
        'discount_vat',
        'paid_amount',
        'round_of_amount',
        'payment_type',
        'is_active'
    ];


    protected $dates = ['deleted_at'];


    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

}
