<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockProduct extends Model
{

use SoftDeletes;
    public $timestamps = false;
    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_id',
        'product_id',
        'type',
        'direction',
        'measure_unit_id',
        'is_vatable',
        'quantity',
        
        'deleted_at',
    ];

    public function stpock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }


    public function stockProductFieldValues()
    {
        return $this->hasMany(StockProductFieldValue::class);
    }
}
