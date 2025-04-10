<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use softDeletes;

    protected $fillable=[
        'name',
        'company_id',
        'is_active',
        'deleted_at'
    ];
}
