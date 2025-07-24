<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class StockReceiveDetail extends Model
{
    use softDeletes, HasFactory;

    protected $casts = [

        'deleted_at' => 'datetime',
    ];

    protected $fillable = [
        'company_id',
        'stock_receive_id',
        'product_id',
        'product_name',
        'quantity',
        'measure_unit_id',
        'batch_no',
        'price',
        'amount'

    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'unit_id');
    }


}
