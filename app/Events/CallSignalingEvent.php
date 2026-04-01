<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallSignalingEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $channelName;
    public $eventData;

    public function __construct($channelName, $eventData)
    {
        $this->channelName = $channelName;
        $this->eventData = $eventData;
    }

    public function broadcastOn()
    {
        return new Channel($this->channelName);
    }

    public function broadcastAs()
    {
        return 'CallStatusChanged';
    }
}