<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('products');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('archived')->default(false);
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->unsignedInteger('quantity')->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->string('source_key')->nullable();
            $table->unique(['company_id', 'source_key']);
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('amount');
            $table->date('paid_at');
            $table->string('method');
            $table->string('reference');
            $table->uuid('request_id')->unique();
            $table->timestamps();
        });
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('account_code', 20);
            $table->string('reference');
            $table->date('from');
            $table->date('to');
            $table->bigInteger('opening_balance');
            $table->bigInteger('closing_balance');
            $table->string('status')->default('draft');
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'account_code', 'reference']);
        });
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('bank_statements');
            $table->date('booked_at');
            $table->string('description');
            $table->bigInteger('amount');
            $table->foreignId('journal_line_id')->nullable()->unique()->constrained('journal_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('refunds');
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'source_key']);
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn('source_key');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('quantity');
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('archived'));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_id'));
    }
};
