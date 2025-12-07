<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeasureUnit extends BaseTenantModel
{
    use SoftDeletes, HasFactory;
    protected $connection = 'tenant';

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',

    ];

    protected $fillable = [
        'name',
        'is_active',
        'is_primary',
        'deleted_at',
       
        'symbol',
        
    ];


    protected $dates = ['deleted_at'];

    

    public function productAssembleDetails()
    {
        return $this->hasMany(ProductionAssemble::class, 'measure_unit_id');
    }
    public function productionSettings()
    {
        return $this->hasMany(ProductionSetting::class, 'measure_unit_id');
    }
    public function productLists()
    {
        return $this->hasMany(ProductList::class, 'measure_unit_id');
    }
    public function purchaseProducts()
    {
        return $this->hasMany(PurchaseProduct::class, 'measure_unit_id');
    }
    public function saleProducts()
    {
        return $this->hasMany(SaleProduct::class, 'measure_unit_id');
    }
    public function products()
    {
        return $this->hasMany(Product::class, 'measure_unit_id');
    }

}
