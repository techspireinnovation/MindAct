<?php

namespace App\Models;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use \Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
    ];

    protected $fillable = [
        'name',
       
        'is_active',
        'is_primary',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    


    public function products()
    {
        return $this->hasMany(Product::class, 'location_id');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'location_id');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'location_id');
    }

    public function productionAssembles()
    {
        return $this->hasMany(ProductionAssemble::class, 'product_location_id');
    }

    public function stockAdjustments()
    {
        return $this->hasMany(StockAdjustment::class, 'location_id');
    }

}
