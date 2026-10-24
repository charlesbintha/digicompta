<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->timestamp('subscription_ends_at')->nullable();
            $t->date('closed_through')->nullable();
            $t->json('ledger_settings')->nullable();
        });
        foreach (['contacts', 'products'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->boolean('archived')->default(false);
            });
        }
        Schema::table('expenses', function (Blueprint $t) {
            $t->string('status')->default('approved');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->string('account_code')->default('601');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('credited')->default(0);
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->unsignedBigInteger('monthly_price')->default(0);
            $t->unsignedInteger('max_users')->nullable();
            $t->unsignedInteger('monthly_invoices')->nullable();
            $t->json('modules');
            $t->unsignedInteger('sla_response_hours')->default(24);
            $t->unsignedInteger('sla_resolution_hours')->default(72);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        foreach (['Essentiel', 'Business', 'Premium'] as $name) {
            DB::table('plans')->insert(['name' => $name, 'active' => false, 'modules' => json_encode(['accounting', 'commerce', 'communications', 'support']), 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('type');
            $t->unsignedBigInteger('value');
            $t->foreignId('plan_id')->nullable()->constrained('plans');
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('subscription_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('plan_id')->constrained();
            $t->foreignId('promotion_id')->nullable()->constrained('promotions');
            $t->string('plan_name');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('discount');
            $t->unsignedBigInteger('total');
            $t->string('status')->default('pending');
            $t->string('payment_reference')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('promotion_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('promotion_id')->constrained('promotions');
            $t->foreignId('company_id')->constrained();
            $t->foreignId('order_id')->constrained('subscription_orders');
            $t->unique(['promotion_id', 'company_id']);
        });
        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->unique(['company_id', 'code']);
        });
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('journal', 10);
            $t->date('entry_date');
            $t->string('label');
            $t->string('source_key');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('journal_entries');
            $t->timestamp('created_at');
            $t->unique(['company_id', 'source_key']);
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('journal_entries');
            $t->string('account_code', 20);
            $t->unsignedBigInteger('debit')->default(0);
            $t->unsignedBigInteger('credit')->default(0);
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->string('status')->default('draft');
            $t->date('valid_until');
            $t->json('items');
            $t->unsignedInteger('tax_basis_points');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('tax_amount');
            $t->unsignedBigInteger('total');
            $t->text('notes')->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices');
            $t->timestamps();
        });
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('invoice_id')->constrained();
            $t->string('reason');
            $t->date('issued_at');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('tax_amount');
            $t->unsignedBigInteger('total');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('supplier_id')->constrained('contacts');
            $t->string('reference');
            $t->string('description');
            $t->date('issue_date');
            $t->date('due_date');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('tax_amount');
            $t->unsignedBigInteger('total');
            $t->string('status')->default('draft');
            $t->string('account_code')->default('601');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'supplier_id', 'reference']);
        });
        Schema::create('purchase_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('purchase_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->date('paid_at');
            $t->string('method');
            $t->uuid('request_id')->unique();
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->integer('quantity');
            $t->string('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('smtp_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained();
            $t->string('host');
            $t->unsignedInteger('port');
            $t->string('encryption');
            $t->string('username');
            $t->text('password');
            $t->string('from_email');
            $t->string('from_name');
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('message_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('channel');
            $t->string('recipient');
            $t->string('subject');
            $t->text('body');
            $t->string('status')->default('queued');
            $t->string('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('title');
            $t->text('body');
            $t->string('target')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('fingerprint', 64);
            $t->text('payload');
            $t->timestamps();
            $t->unique(['company_id', 'user_id', 'fingerprint']);
        });
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->string('subject');
            $t->string('category');
            $t->string('priority');
            $t->string('status')->default('open');
            $t->timestamp('response_due_at');
            $t->timestamp('resolution_due_at');
            $t->timestamp('responded_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('sla_alerted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ticket_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets');
            $t->foreignId('user_id')->constrained();
            $t->text('body');
            $t->boolean('is_staff')->default(false);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['ticket_replies', 'support_tickets', 'push_subscriptions', 'user_notifications', 'message_outbox', 'smtp_settings', 'stock_movements', 'purchase_payments', 'purchases', 'credit_notes', 'quotes', 'journal_lines', 'journal_entries', 'ledger_accounts', 'promotion_redemptions', 'subscription_orders', 'promotions', 'plans'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('credited'));
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['status', 'account_code']);
        });
        foreach (['contacts', 'products'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('archived'));
        }
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['subscription_ends_at', 'closed_through', 'ledger_settings']));
    }
};
