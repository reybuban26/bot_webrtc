<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the chat_status ENUM to include new AI-related statuses
        // 'waiting' -> user sends first message, AI will respond
        // 'ai_active' -> AI is handling the conversation
        // 'escalating' -> escalation triggered, waiting for admin
        // 'active' -> human admin is now handling (was 'human_escalated')
        // 'ended' -> chat ended
        DB::statement(
            "ALTER TABLE support_threads
             MODIFY COLUMN chat_status ENUM('waiting','ai_active','escalating','active','ended')
             NOT NULL DEFAULT 'waiting'"
        );
    }

    public function down(): void
    {
        // Revert to original enum (remove ai_active, escalating, rename active back conceptually)
        DB::statement(
            "ALTER TABLE support_threads
             MODIFY COLUMN chat_status ENUM('waiting','active','ended')
             NOT NULL DEFAULT 'waiting'"
        );
    }
};
