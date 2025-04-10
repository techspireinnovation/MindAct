<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    protected $fillable=[
        'name',
        'company_id',
        'is_active',
        'deleted_at'
    ];
}
