<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 100);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        Schema::create('invoice_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('threshold')->default(0);
            $table->boolean('prevent_self_approval')->default(false);
            $table->boolean('distinct_approvers')->default(true);
            $table->json('steps');
            $table->timestamps();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('approval_snapshot')->nullable();
            $table->unsignedInteger('approval_round')->default(0);
            $table->unsignedInteger('approval_step')->default(0);
        });
        DB::table('invoices')->where('status', 'pending')->update([
            'approval_round' => 1,
            'approval_snapshot' => json_encode(['steps' => [['role' => 'any', 'name' => 'Validation', 'role_name' => 'Validateur autorisé']], 'prevent_self_approval' => false, 'distinct_approvers' => false]),
        ]);
        Schema::create('invoice_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('round');
            $table->unsignedInteger('step');
            $table->string('step_name');
            $table->string('action');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
            $table->unique(['invoice_id', 'round', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_approvals');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['approval_snapshot', 'approval_round', 'approval_step']);
        });
        Schema::dropIfExists('invoice_workflows');
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('company_roles');
    }
};
