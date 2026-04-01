<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('support_threads')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->text('body')->nullable();
            // text = plain message
            // call_started = system message when call begins
            // call_ended = system message when call ends (with duration)
            // meeting_notes = AI-generated notes after a call
            $table->enum('type', ['text', 'call_started', 'call_ended', 'meeting_notes'])->default('text');
            $table->json('metadata')->nullable(); // call_id, duration_secs, notes, recording_path etc.
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
