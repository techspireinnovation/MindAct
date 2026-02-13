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
        'reference_no',
        'bill_number',
        'invice_date',
        'invoice_date_bs',

        'deleted_at',
    ];

    public function stockProducts()
    {
        return $this->hasMany(StockProduct::class);
    }
}
