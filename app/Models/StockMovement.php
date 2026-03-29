<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class StockMovement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_id',
        'stock_product_id',
        'stock_transaction_id',
        'sales_bill_number',
        'source_id',
        'source_type',
        'party_id',
        'product_id',
        'type',
        'stock_type',
        'quantity',
        'quantity_index',
        'direction',
        'measure_unit_id',
        'batch_no',
        'is_vatable',
        'mfd',
        'expiry_date',
        'price',
        'discount_percent',
        'discount_amount',
        'amount',
    ];

    public function stockProduct()
    {
        return $this->belongsTo(StockProduct::class);

    }

    public function transactionPivots()
    {
        return $this->hasMany(TransactionPivot::class);
    }

    public function stockProductFieldValues()
    {
        return $this->hasMany(StockProductFieldValue::class);
    }



}
