<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->default('SN');
            $table->string('currency', 3)->default('XOF');
            $table->string('address')->nullable();
            $table->string('ninea')->nullable();
            $table->string('rccm')->nullable();
            $table->string('plan')->default('Essentiel');
            $table->string('subscription_status')->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedBigInteger('invoice_sequence')->default(0);
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->unique(['company_id', 'user_id']);
            $table->timestamps();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('type');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'type']);
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('type')->default('service');
            $table->unsignedBigInteger('price');
            $table->timestamps();
            $table->index('company_id');
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('contact_id')->constrained('contacts');
            $table->string('number')->nullable();
            $table->string('status')->default('draft');
            $table->date('issue_date');
            $table->date('due_date');
            $table->json('customer_snapshot');
            $table->json('seller_snapshot');
            $table->unsignedInteger('tax_basis_points')->default(0);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('tax_amount');
            $table->unsignedBigInteger('total');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('company_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->date('paid_at');
            $table->string('method');
            $table->uuid('request_id')->unique();
            $table->timestamps();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('description');
            $table->string('category');
            $table->unsignedBigInteger('amount');
            $table->date('paid_at');
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->timestamp('created_at');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'expenses', 'payments', 'invoice_items', 'invoices', 'products', 'contacts', 'memberships', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
