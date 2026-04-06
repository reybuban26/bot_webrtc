<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE support_messages MODIFY COLUMN type ENUM('text', 'meeting_notes', 'call_started', 'call_ended', 'file') NOT NULL DEFAULT 'text'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         DB::statement("ALTER TABLE support_messages MODIFY COLUMN type ENUM('text', 'meeting_notes', 'call_started', 'call_ended') NOT NULL DEFAULT 'text'");
    }
};
