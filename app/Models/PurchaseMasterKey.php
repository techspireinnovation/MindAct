<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseMasterKey extends Model
{
    use SoftDeletes, HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [

            'company_id',
            'product_code',
            'free',
            'discount_percent',
            'discount_amount',
            'discount',
            'excise_duty',
            'health_insurance',
            'freight_charge',
            'discount_after_vat',
            'expiry_date',
            'batch_no',
            'mfd',
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
