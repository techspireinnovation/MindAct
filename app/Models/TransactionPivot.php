<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionPivot extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_product_id',
        'stock_transaction_id',
        'stock_movement_id',
        'product_id',
        'quantity_index',
        'quantity_type',
        'direction',
        'type'


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
}
