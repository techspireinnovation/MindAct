<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShrinkingWorkingLoss extends Model
{
    use softDeletes,HasFactory;

   protected $casts = [
        'product_details' => 'array',
    ];

    protected $fillable = [
        'company_id',
        'date_from',
        'date_to',
        'product_id',
        'shrinking_loss_percent',
        'working_loss_percent',
        'internal_loss_percent',
        'adjustment_ref_no',
        'product_details',
        'total_purchase_quantity',
        'total_loss_quantity',
       
   
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
