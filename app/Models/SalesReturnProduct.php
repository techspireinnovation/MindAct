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
        'sale_product_id',
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

     public function saleReturn()
       {
           return $this->belongsTo(SalesReturn::class);
       }

       public function saleProduct()
       {
           return $this->belongsTo(SaleProduct::class);
       }

       public function product()
       {
           return $this->belongsTo(Product::class);
       }

       public function customer()
       {
           return $this->belongsTo(Customer::class);
       }

       public function measureUnit()
       {
           return $this->belongsTo(MeasureUnit::class);
       }

       public function fieldValues()
       {
           return $this->hasMany(SaleReturnProductFieldValue::class, 'sale_return_product_id');
       }




    
}
