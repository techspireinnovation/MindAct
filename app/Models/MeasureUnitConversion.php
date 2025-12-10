<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;


class MeasureUnitConversion extends BaseTenantModel
{

    protected $fillable = [

        'product_id',
        'from_unit_id',
        'to_unit_id',
        'conversion_factor'

    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function fromUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'from_unit_id');
    }

    public function toUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'to_unit_id');
    }
}
