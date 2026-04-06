<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageSeen implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public int $threadId,
        public int $seenByUserId,
        public string $seenByName
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("support.thread.{$this->threadId}");
    }

    public function broadcastAs(): string
    {
        return 'message.seen';
    }
}