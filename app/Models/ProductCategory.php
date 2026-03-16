<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductCategory extends BaseTenantModel
{
    use HasFactory;
    use softDeletes;
    // protected $connection = 'tenant';

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];

    protected $fillable = [
        'name',

        'parent_id',
        'is_active',
        'is_primary',
        'deleted_at'
    ];


    protected $date = ['deleted_at'];




    public function products()
    {
        return $this->hasMany(Product::class, 'category_id')->whereNull('deleted_at');
    }

    public function subCategory()
    {
        return $this->hasMany(ProductSubCategory::class, 'category_id')->whereNull('deleted_at');
    }



}
