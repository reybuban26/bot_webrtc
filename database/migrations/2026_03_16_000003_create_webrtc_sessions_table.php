<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webrtc_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('room_id', 32)->unique();
            $table->string('host_peer_id', 64)->nullable();
            $table->string('guest_peer_id', 64)->nullable();
            $table->enum('status', ['waiting', 'active', 'ended'])->default('waiting');
            $table->json('signals')->nullable();  // Polling-based signal queue
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webrtc_sessions');
    }
};
