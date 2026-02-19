<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransaction extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_id',
        'product_id',
        'stock_product_id',
        'type',
        'direction',
        'sales_bill_number',
        'measure_unit_id',
        'is_vatable',
        'quantity',
        'party_id',
        'expiry_date',
        'mfd',
        'type',
        'price',
        'discount_percent',
        'discount_amount',
        'amount',
        'batch_no',
        'direction',
        'measure_unit_id',
        'quantity',
        'deleted_at',
    ];

    public function stock()
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

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

     public function transactionPivots()
    {
        return $this->hasMany(TransactionPivot::class);
    }
}
