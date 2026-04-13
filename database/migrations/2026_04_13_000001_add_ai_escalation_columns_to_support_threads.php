<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->decimal('ai_confidence', 3, 2)->nullable()->after('chat_status')->comment('AI response confidence (0.00-1.00)');
            $table->boolean('requires_escalation')->default(false)->after('ai_confidence')->comment('Escalation was requested by AI or user');
            $table->integer('queue_position')->nullable()->after('requires_escalation')->comment('Position in escalation queue');
        });
    }

    public function down(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropColumn(['ai_confidence', 'requires_escalation', 'queue_position']);
        });
    }
};
