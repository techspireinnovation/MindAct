<?php

namespace App\Models;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends BaseTenantModel
{
    use HasFactory, SoftDeletes;
    protected $connection = 'tenant';

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'delete_status' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_active',
        'is_primary',
        'delete_status',
        'deleted_at',
       
    ];

    protected $dates = ['deleted_at'];

   

    public function products()
    {
        return $this->hasMany(Product::class, 'product_type_id');
    }

}
