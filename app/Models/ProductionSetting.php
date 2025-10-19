<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionSetting extends BaseTenantModel
{
    use softDeletes,HasFactory;

   protected $casts = [
        'product_details' => 'array',
    ];

    protected $fillable = [
        'company_id',
        'date',
        'document_no',
        'product_id',
        'product_name',
        'quantity',
        'measure_unit_id',
        'product_details',
       

        
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function settingDetail():HasMany{
        return $this->hasMany(ProductionSettingDetail::class); 
    }

 
}


