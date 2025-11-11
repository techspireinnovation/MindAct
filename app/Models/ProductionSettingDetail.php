<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionSettingDetail extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [
        'product_details' => 'array',
    ];

    protected $fillable = [
        'company_id',
        'production_setting_id',
        'product_id',
        'product_name',
        'quantity',
        'measure_unit_id',
        'amount',
        'price',
        'deleted_at',

    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function settingDetail(): HasMany
    {
        return $this->hasMany(ProductionSettingDetail::class);
    }



}
