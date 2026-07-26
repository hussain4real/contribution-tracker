<?php

declare(strict_types=1);

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
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->foreignId('payment_batch_id')
                ->nullable()
                ->after('status')
                ->constrained('payment_batches')
                ->nullOnDelete();
            $table->foreignId('fee_expense_id')
                ->nullable()
                ->after('payment_batch_id')
                ->constrained('expenses')
                ->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('fee_expense_id');
            $table->timestamp('allocated_at')->nullable()->after('verified_at');
            $table->timestamp('failed_at')->nullable()->after('allocated_at');
            $table->string('failure_reason')->nullable()->after('failed_at');

            $table->unique('payment_batch_id');
            $table->unique('fee_expense_id');
            $table->index(['family_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropIndex(['family_id', 'status', 'created_at']);
            $table->dropUnique(['payment_batch_id']);
            $table->dropUnique(['fee_expense_id']);
            $table->dropConstrainedForeignId('payment_batch_id');
            $table->dropConstrainedForeignId('fee_expense_id');
            $table->dropColumn(['verified_at', 'allocated_at', 'failed_at', 'failure_reason']);
        });
    }
};
