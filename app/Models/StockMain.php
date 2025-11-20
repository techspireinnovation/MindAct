<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;



class StockMain extends Model
{
    use HasFactory, softDeletes;

    protected $fillable = [
        'name',
        'code',
        'total_quantity',
        'company_id',
        'total_amount',
        'branch_id'
    ];

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class);
    }
    protected static function booted()
    {
        static::deleting(function ($stockMain) {
            $stockMain->stockEntries()->each(function ($entry) {
                $entry->fieldValues()->delete();
                $entry->delete();
            });
        });
    }

}
