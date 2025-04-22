<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $casts = ['data' => 'array'];
    protected $fillable = ['read_at'];

    public function markAsRead()
    {
        $this->update(['read_at' => now()]);
    }
}
