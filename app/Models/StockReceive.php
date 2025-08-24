<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockReceive extends Model
{
    use softDeletes,HasFactory;

   

    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_receive_id',
        'transfer_ref_no',
        'reference_no',
        'receive_from',
        'current_location',
        'address',
        'document_no',
        'current_date',
        'current_date_bs',
        'stock_transfer_date',
        'stock_transfer_date_bs',
        'product_details',
        'reasons',
        'remarks'
        
        
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function stockReceiveDetails(): HasMany
    {
        return $this->hasMany(StockReceiveDetail::class, 'stock_receive_id');
    }
}
