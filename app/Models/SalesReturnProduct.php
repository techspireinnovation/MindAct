<?php

namespace App\Models;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class SalesReturnProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];
    protected $fillable = [
        'company_id',
        'product_id',  
        'expiry_date',
        'quantity',
        'free_quantity',  
        'price',  
        'discount_percent',
        'discount_amount',
        'is_vatable',  
        'measure_unit_id',
    ];
    
   

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }




    
}
