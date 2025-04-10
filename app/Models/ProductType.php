<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'deleted_at',
        'company_id',
    ];
    use SoftDeletes;
    protected $dates = ['deleted_at'];
}
