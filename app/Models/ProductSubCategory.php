<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class ProductSubCategory extends Model
{
    
    use softDeletes;
    
    protected $fillable=[
        'name',
        'company_id',
        'category_id',
        'is_active'
        
    ];
}
