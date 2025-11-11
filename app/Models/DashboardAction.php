<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardAction extends Model
{
    protected $fillable = [
        'main',
        'submain',
        'route',
        'active',
    ];
}
