<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockProductFieldValue extends Model
{
    use SoftDeletes;
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_id',
        'stock_product_id',
        'stock_movement_id',
        'product_id',
        'quantity_index',
        'quantity_type',
        'key',
        'value',
        'deleted_at',
    ];
}
