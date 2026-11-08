<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial infrastructure: payment accounts (cash drawers, banks, wallets),
 * the money movement journal and per-tenant document numbering.
 *
 * Transaction convention (bank statement style):
 *   credit = money IN to the account (sale, customer payment)
 *   debit  = money OUT of the account (purchase, vendor payment, expense)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); // null = shop-wide (banks)
            $table->string('account_name');
            $table->string('account_type', 20); // cash_drawer | bank | wallet
            $table->string('bank_name')->nullable();
            $table->string('account_number', 60)->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'account_type']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_account_id')->constrained()->restrictOnDelete();
            // purchase | sale | vendor_payment | customer_payment | expense | sale_return | fund_transfer | adjustment | opening
            $table->string('reference_type', 30);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('type', 10); // debit | credit
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_account_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('payment_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('payment_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('transfer_date');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('fund_transfers');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_accounts');
    }
};
