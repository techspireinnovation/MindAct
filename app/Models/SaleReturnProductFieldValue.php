<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleReturnProductFieldValue extends BaseTenantModel
{
    use SoftDeletes;

       protected $fillable = [
           'sale_return_product_id',
           'purchase_product_id',
           'sale_product_id',
           'product_field_id',
           'value',
           'product_id',
           'company_id',
           'quantity_index',
           'quantity_type'
       ];

       protected $dates = ['deleted_at'];

       public function saleReturnProduct()
       {
           return $this->belongsTo(SalesReturnProduct::class, 'sale_return_product_id');
       }

       public function productField()
       {
           return $this->belongsTo(ProductField::class);
       }

       public function product()
       {
           return $this->belongsTo(Product::class);
       }

       protected static function booted()
       {
           static::addGlobalScope(new CompanyIdScope());
           static::creating(function ($model) {
               if (empty($model->company_id)) {
                   $model->company_id = Request::input('company_id');
               }
           });
       }
}
