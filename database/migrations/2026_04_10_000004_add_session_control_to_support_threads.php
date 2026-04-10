<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->enum('chat_status', ['waiting', 'active', 'ended'])
                  ->default('waiting')
                  ->after('title');

            $table->unsignedBigInteger('assigned_admin_id')
                  ->nullable()
                  ->after('chat_status');

            $table->foreign('assigned_admin_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropForeign(['assigned_admin_id']);
            $table->dropColumn(['chat_status', 'assigned_admin_id']);
        });
    }
};
