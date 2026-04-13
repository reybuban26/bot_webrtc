<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add new message types for AI support
        // 'ai_response' -> message from AI agent
        // 'escalation_request' -> system message when escalation triggered
        DB::statement(
            "ALTER TABLE support_messages
             MODIFY COLUMN type ENUM('text','call_started','call_ended','meeting_notes','file','system','ai_response','escalation_request')
             NOT NULL DEFAULT 'text'"
        );
    }

    public function down(): void
    {
        // Revert to previous enum (remove ai_response, escalation_request)
        DB::statement(
            "ALTER TABLE support_messages
             MODIFY COLUMN type ENUM('text','call_started','call_ended','meeting_notes','file','system')
             NOT NULL DEFAULT 'text'"
        );
    }
};
