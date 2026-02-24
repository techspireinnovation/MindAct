<?php

namespace App\Models;


use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class ProductSubCategory extends BaseTenantModel
{

    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'name',
        
        'category_id',
        'is_active',
        'deleted_at'

    ];

    protected $dates = ['deleted_at'];



    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }
    public function productscategory()
    {
        return $this->hasMany(Product::class, 'sub_category_id');
    }
}
