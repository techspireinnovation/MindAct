<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\softDeletes;

class ProductField extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'values' => 'array',
    ];

    protected $fillable = [
        'name',
        'type',
        'values',
        'company_id',
        'is_active',
        'deleted_at'
    ];



    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

  

    public function productFieldValues()
    {
        return $this->hasMany(ProductFieldValue::class, 'product_field_id');
    }
    public function purchaseProductFieldValues()
    {
        return $this->hasMany(PurchaseProductFieldValue::class, 'product_field_id');
    }
    public function salesProductFieldValues()
    {
        return $this->hasMany(SalesProductFieldValue::class, 'product_field_id');
    }
}
