<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            // Did the user feel their issue was resolved? true=Yes, false=No, null=not answered
            $table->boolean('is_resolved_by_user')->nullable()->after('resolution_status');
            // 1–5 star rating from user
            $table->tinyInteger('feedback_rating')->unsigned()->nullable()->after('is_resolved_by_user');
            // Optional written comment from user
            $table->text('feedback_comment')->nullable()->after('feedback_rating');
        });
    }

    public function down(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropColumn(['is_resolved_by_user', 'feedback_rating', 'feedback_comment']);
        });
    }
};
