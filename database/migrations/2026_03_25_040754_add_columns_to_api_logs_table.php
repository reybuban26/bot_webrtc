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
        Schema::table('api_logs', function (Blueprint $table) {
            $table->string('service')->nullable()->after('id');
            $table->string('endpoint')->nullable()->after('service');
            $table->string('method')->nullable()->after('endpoint');
            $table->integer('status_code')->nullable()->after('method');
            $table->json('request_payload')->nullable()->after('status_code');
            $table->json('response_payload')->nullable()->after('request_payload');
            $table->text('error_message')->nullable()->after('response_payload');
            $table->integer('duration_ms')->nullable()->after('error_message');
            $table->string('ip_address')->nullable()->after('duration_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropColumn([
                'service', 'endpoint', 'method', 'status_code', 
                'request_payload', 'response_payload', 'error_message', 
                'duration_ms', 'ip_address'
            ]);
        });
    }
};
