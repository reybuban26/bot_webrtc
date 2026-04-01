<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_requests', function (Blueprint $table) {
            // For admin-initiated calls: admin = caller_id, target_user_id = user being called.
            // For user-initiated calls: target_user_id is null (targets all admins).
            $table->unsignedBigInteger('target_user_id')->nullable()->after('caller_id');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('call_requests', function (Blueprint $table) {
            $table->dropForeign(['target_user_id']);
            $table->dropColumn('target_user_id');
        });
    }
};
