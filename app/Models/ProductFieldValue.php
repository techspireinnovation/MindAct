<?php

namespace App\Models;


use App\Models\ProductField;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\softDeletes;
use Illuminate\Database\Eloquent\Model;

class ProductFieldValue extends Model
{
    use softDeletes;

    protected $fillable = [
        'company_id',
        'product_field_id',
        'value',
        'deleted_at'
    ];


    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function productField(){
        return $this->belongsTo(ProductField::class, 'product_field_id');
    }



}


