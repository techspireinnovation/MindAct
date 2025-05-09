<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockEntry extends Model
{
    use softDeletes,HasFactory;

    protected $fillable = [
        'product_code',
        'company_id',
        'product_id',
        'uom',
        'batch_no',
        'expiry_date',
        'quantity',
        'rate',
        'amount',
        'location_id'
    ];

}
