<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\softDeletes;

class ProductList extends Model
{

    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
    ];

    protected $fillable = [
        'name',
        'product_id',
        'barcode',
        'hs_code',
        'price',
        'discount',
        'final_price',        
        'primary_measure_unit_id',
        'measure_unit_id',
        'is_active',
        'deleted_at'
    ];



    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

}
