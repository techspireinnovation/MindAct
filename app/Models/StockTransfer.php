<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use softDeletes,HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
       
    ];

    protected $fillable = [
        'company_id',
        'transfer_to',
        'reference_no',
        'document_no',
        'current_location',
        'transaction_date_bs',
        'date_ad',
        'remarks',
        'reason_for',
        'is_active',
        'product_details',
        
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function stockTransferDetails(): HasMany {
        return $this->hasMany(StockTransferDetails::class, 'stock_transfer_id');
    }
}
