<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionAssemble extends Model
{
    use softDeletes,HasFactory;

   protected $casts = [
        'product_details' => 'array',
    ];

    protected $fillable = [
        'company_id',
        'production_id',
        'product_name',
        'measure_unit_id',
        'product_quantity',
        'production_date',
        'production_no',
        'product_location_id',
        'document_no',
        'batch_no',
        'product_details',
        'total_rm_amount',
        'product_damage_quantity',
        'finish_product_qauntity',
        'finish_cost_per_unit',
        'product_defect_quantity',
        'total_product_cost',
        'remarks',
   
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

}
