<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ChatEnded implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public int    $threadId,
        public int    $messageId,
        public string $body,
        public string $createdAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("support.thread.{$this->threadId}");
    }

    public function broadcastAs(): string
    {
        return 'chat.ended';
    }
}
