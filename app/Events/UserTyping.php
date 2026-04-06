<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UserTyping implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public int $threadId,
        public int $userId,
        public string $userName,
        public bool $isTyping
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("support.thread.{$this->threadId}");
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }
}