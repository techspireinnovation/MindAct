<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesMasterKey extends Model
{
    use SoftDeletes, HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [

            'company_id',
            'salesman_id',
            'salesman',
            'credit_days',
            'balance',
            'store',
            'location',
            'direct_mail_system',
            'direct_whatsapp_system',
            'bill_type',
            'discount_percent',
            'free',
            'product_code',
            'discount',
            'discount_amount',
            'additional',
            'mfd',
            'excise_duty',
            'health_insurance',
            'freight_charge',
            'discount_after_vat',
            'expiry_date',
            'batch_no'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

}
