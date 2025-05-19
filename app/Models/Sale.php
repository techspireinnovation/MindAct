<?php

namespace App\Models;
use App\Models\SaleProduct;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'payment' => 'array'
    ];

    protected $fillable = [
            'company_id',
            'customer_id',
            'balance',
            'invoice_number',
            'invoice_date' ,
            'batch_no', 
            'payment',
            'document_number',
            'store_id',
            'location_id',
            'salesman_id',
            'discount',
            'excise_duty',
            'health_insurance',
            'freight_charge',
            'discount_after_vat',
            'round_off_amount',
            'payment_type',
            'is_mail_notify',
            'is_whatsapp_notify',
    ];


    protected $dates = ['deleted_at'];


    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
    public function saleProducts(): HasMany
    {
        return $this->hasMany(SaleProduct::class);
    }

    // in App\Models\Sale.php

public function saleAdditionals()
{
    return $this->hasMany(SaleAdditional::class,'sale_id'); // adjust class name if needed
}


}
