<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform (SaaS) + shop/location architecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->string('domain')->nullable()->unique();   // reserved for custom domains / e-commerce
            $table->string('status', 20)->default('active')->index(); // active | suspended
            $table->string('owner_email');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('plan', 30)->default('basic');
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('currency_symbol', 10)->default('Rs.');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('super_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->decimal('default_cash_drawer_balance', 14, 2)->default(0);
            $table->boolean('is_main')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();       // staff log in at /app without a shop slug
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->string('role', 20)->default('cashier'); // owner | manager | cashier
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['tenant_id', 'role']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('super_admins');
        Schema::dropIfExists('tenants');
    }
};
