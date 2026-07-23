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
        Schema::create('provider_settlement_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('paystack');
            $table->string('reference');
            $table->date('settled_at');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('bank_amount')->nullable();
            $table->bigInteger('difference')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['family_id', 'provider', 'reference']);
            $table->index(['family_id', 'settled_at']);
        });

        Schema::create('provider_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_settlement_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paystack_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('net_amount');
            $table->timestamps();

            $table->unique('paystack_transaction_id');
            $table->index(['provider_settlement_group_id', 'payment_batch_id'], 'settlement_items_group_batch_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_settlement_items');
        Schema::dropIfExists('provider_settlement_groups');
    }
};
