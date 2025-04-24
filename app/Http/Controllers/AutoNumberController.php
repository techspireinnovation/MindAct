<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AutoNumberController extends Controller
{
    function getAutoNumbers()
    {
        return response()->json(['error' => 'Main Group not found!!'], 404);
    }
}
