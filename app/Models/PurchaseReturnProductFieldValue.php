<?php

   namespace App\Models;

   use App\Models\Scopes\CompanyIdScope;
   use Illuminate\Database\Eloquent\Model;
   use Illuminate\Database\Eloquent\SoftDeletes;
   use Illuminate\Http\Request;

   class PurchaseReturnProductFieldValue extends Model
   {
       use SoftDeletes;

       protected $fillable = [
           'purchase_return_product_id',
           'product_field_id',
           'value',
           'product_id',
           'company_id',
           'quantity_index',
       ];

       protected $dates = ['deleted_at'];

       public function purchaseReturnProduct()
       {
           return $this->belongsTo(PurchaseProductReturn::class, 'purchase_return_product_id');
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