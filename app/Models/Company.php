<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'licence_issue_date',
        'working_date',
        'reg_number',
        'pan_number',
        'vat_number',
        'is_vatable',
        'full_address',
        'email_address',
        'website',
        'fax',
        'logo',
        'province',
        'district',
        'palika_name',
        'ward_number',
        'contact_person',
        'contact_person_position',
        'agreement_holder_name',
        'phone',
        'position',
        'license_number',
        'activation_key',
        'url_link',
        'deleted_at'

    ];

    public function purchaseMasterKey(): HasOne
    {
        return $this->hasOne(PurchaseMasterKey::class);
    }
    public function salesMasterKey(): HasOne
    {
        return $this->hasOne(SalesMasterKey::class);
    }

    public function productTypes(){
        return $this->hasMany(ProductType::class);
    }

    public function measureUnits(){
        return $this->hasMany(MeasureUnit::class);
    }

}
