<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_requests', function (Blueprint $table) {
            $table->unsignedInteger('duration')->nullable()->after('status')
                  ->comment('Call duration in seconds');
            $table->timestamp('started_at')->nullable()->after('duration');
            $table->timestamp('ended_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_requests', function (Blueprint $table) {
            $table->dropColumn(['duration', 'started_at', 'ended_at']);
        });
    }
};