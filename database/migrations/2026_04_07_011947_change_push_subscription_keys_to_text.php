<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->text('p256dh')->nullable()->change();
            $table->text('auth')->nullable()->change();
            $table->text('endpoint')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('p256dh')->nullable()->change();
            $table->string('auth')->nullable()->change();
            $table->string('endpoint')->change();
        });
    }
};
