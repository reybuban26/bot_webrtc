<?php

namespace App\Jobs;

use App\Models\ChatSession;
use App\Services\QwenAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateChatTitle implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;        // retry once kung mag-timeout
    public int $timeout = 60;     // 30 seconds lang ang pag-hintay

    public function __construct(public ChatSession $session) {}

    public function handle(QwenAiService $qwen): void
    {
        $title = $qwen->generateTitle($this->session);
        if ($title) {
            $this->session->update(['title' => $title]);
        }
    }
}