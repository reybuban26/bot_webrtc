<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->string('status')->default('open')->after('title')
                  ->comment('open | resolved | closed');
            $table->timestamp('resolved_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropColumn(['status', 'resolved_at']);
        });
    }
};