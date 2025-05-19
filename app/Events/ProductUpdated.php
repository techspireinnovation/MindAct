<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ProductUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $products;
    public $action;

    public function __construct($products, $action)
    {
        $this->products = $products;

        $this->action = $action;
      
    }

    public function broadcastOn()
    {
        return new Channel('products');
    }

    public function broadcastAs()
    {
        return 'ProductUpdated';
    }
}