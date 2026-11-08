<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting support:
 *  - stock_movements.moved_at: the business date of a stock change (a lot
 *    dated last week moves stock last week), used to rebuild stock history.
 *  - stock_snapshots: daily per-branch stock value, captured by the scheduler
 *    so valuation charts use the cost price that applied on each day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dateTime('moved_at')->nullable()->after('note');
        });

        DB::table('stock_movements')->whereNull('moved_at')->update(['moved_at' => DB::raw('created_at')]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['tenant_id', 'moved_at']);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->index(['tenant_id', 'return_date']);
        });

        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->integer('units')->default(0);
            $table->decimal('cost_value', 16, 2)->default(0);
            $table->decimal('retail_value', 16, 2)->default(0);
            $table->boolean('reconstructed')->default(false); // rebuilt from movements at current cost
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'snapshot_date']);
            $table->index(['tenant_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_snapshots');

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'return_date']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'moved_at']);
            $table->dropColumn('moved_at');
        });
    }
};
