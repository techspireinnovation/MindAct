<?php

namespace App\Models;

use Carbon\Traits\Timestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Stock extends Model
{
    use SoftDeletes;

    public $timestamps = false;
    protected $fillable = [
        'fiscal_year_id',
        'company_id',
        'branch_id',
        'store_id',
        'type',
        
        
        'bill_number',
        'purchase_bill_number',
        'invoice_date',
        'invoice_date_bs',
        'party_id',
        'location_id',
        'batch_no',
        'credit_days',
        'balance',
        'ref_bill_number',
        'return_bill_no',
        'reasons',
        'discount_type',
        'discount_value',
        'discount_after_vat',
        'sub_total_before_discount',
        'taxable_amount',
        'non_taxable_amount',
        'excise_duty',
        'vat_percent',
        'health_insurance',
        'freight_amount',
        'roundoff_type',
        'roundoff_amount',
        'total_amount',
        'payment',
        'remarks',
    ];


    public function stockProducts()
    {
        return $this->hasMany(StockProduct::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

     public function stockTransactions()
    {
        return $this->hasMany(StockTransaction::class);
    }
}
