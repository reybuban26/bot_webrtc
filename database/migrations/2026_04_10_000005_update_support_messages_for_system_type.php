<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop FK so we can modify the column
        DB::statement('ALTER TABLE support_messages DROP FOREIGN KEY support_messages_sender_id_foreign');

        // 2. Make sender_id nullable (system messages have no sender)
        DB::statement('ALTER TABLE support_messages MODIFY COLUMN sender_id BIGINT UNSIGNED NULL');

        // 3. Re-add FK with SET NULL so deleting a user nulls the column
        DB::statement(
            'ALTER TABLE support_messages ADD CONSTRAINT support_messages_sender_id_foreign
             FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL'
        );

        // 4. Extend the type ENUM to include the new "system" value
        DB::statement(
            "ALTER TABLE support_messages
             MODIFY COLUMN type ENUM('text','call_started','call_ended','meeting_notes','file','system')
             NOT NULL DEFAULT 'text'"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE support_messages DROP FOREIGN KEY support_messages_sender_id_foreign');
        DB::statement('ALTER TABLE support_messages MODIFY COLUMN sender_id BIGINT UNSIGNED NOT NULL');
        DB::statement(
            'ALTER TABLE support_messages ADD CONSTRAINT support_messages_sender_id_foreign
             FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE'
        );
        DB::statement(
            "ALTER TABLE support_messages
             MODIFY COLUMN type ENUM('text','call_started','call_ended','meeting_notes','file')
             NOT NULL DEFAULT 'text'"
        );
    }
};
