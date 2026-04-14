<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->enum('resolution_status', ['pending', 'resolved'])
                  ->nullable()
                  ->after('metadata')
                  ->comment('Set when chat ends: resolved = issue fixed, pending = needs follow-up');
        });
    }

    public function down(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropColumn('resolution_status');
        });
    }
};
