<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_orders', function (Blueprint $table) {
            $table->foreignId('wave_requested_by')->nullable()->constrained('users');
            $table->uuid('wave_reference')->nullable()->unique();
            $table->string('wave_session_id')->nullable()->unique();
            $table->string('wave_transaction_id')->nullable()->unique();
            $table->string('wave_state')->nullable();
            $table->text('wave_launch_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wave_requested_by');
            $table->dropColumn(['wave_reference', 'wave_session_id', 'wave_transaction_id', 'wave_state', 'wave_launch_url']);
        });
    }
};
