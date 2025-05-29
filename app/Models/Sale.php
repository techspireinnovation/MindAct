<?php

namespace App\Models;

use App\Models\SaleProduct;
use App\Models\SaleAdditional;
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
        'is_mail_notify' => 'boolean',
        'is_whatsapp_notify' => 'boolean',
        'is_vatable' => 'boolean',
        'abvt' => 'boolean',
        'payment' => 'array',
        'invoice_date' => 'date',
        'invoice_date_bs' => 'date',
    ];

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        'customer_id',
        'customer_name',
        'sale_id',
        'customer_address',
        'credit_days',
        'balance',
        'invoice_number',
        'batch_no',
        'invoice_date',
        'invoice_date_bs',
        'document_number',
        'contact_number',
        'ref_number',
        'pan_number',
        'remarks',
        'store_id',
        'location_id',
        'salesman_id',
        'sub_total_before_discount',
        'discount',
        'non_taxable_amount',
        'taxable_amount',
        'excise_duty',
        'health_insurance',
        'freight_charge',
        'discount_after_vat',
        'round_off_amount',
        'total_amount',
        'payment',
        'note',
        'is_mail_notify',
        'is_vatable',
        'abvt',
        'is_whatsapp_notify',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function saleProducts(): HasMany
    {
        return $this->hasMany(SaleProduct::class);
    }

    public function saleAdditionals(): HasMany
    {
        return $this->hasMany(SaleAdditional::class, 'sale_id');
    }
}
