<?php

namespace App\Models;


use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;

class ProductSubCategory extends Model
{
    
    use softDeletes;
    
    protected $fillable=[
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

    public function category(){
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }
}
