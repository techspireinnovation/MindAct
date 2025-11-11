<?php

namespace App\Models;


use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;

class ProductSubCategory extends BaseTenantModel
{

    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'name',
        'company_id',
        'category_id',
        'is_active',
        'deleted_at'

    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }
    public function productscategory()
    {
        return $this->hasMany(Product::class, 'sub_category_id');
    }
}
