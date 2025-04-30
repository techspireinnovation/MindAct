<?php

namespace App\Http\Controllers\Event;

use App\Models\Product;
use App\Events\ProductUpdate;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProductEventController extends Controller
{
    public function index(){
        $products = Product::all();
        \Log::debug('Broadcasting products:', $products->toArray()); // Add this line

        broadcast(new ProductUpdate($products));
        return response()->json([
            'message' => 'Broadcast sent successfully',
            'products_count' => $products
        ]);
    }
}
