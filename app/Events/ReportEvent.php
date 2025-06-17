<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;
    public $companyId;
    public $item;
    /**
     * Create a new event instance.
     */
    public function __construct($companyId, $item)
    {
        $this->companyId = $companyId;
        $this->item = $item;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->item;
    }


    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return ["accessPipe_{$this->companyId}"];
    }
}
