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
        Schema::create('payment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('family_membership_id')->nullable()->constrained('family_members')->nullOnDelete();
            $table->string('member_name');
            $table->integer('total_amount');
            $table->date('paid_at');
            $table->string('method');
            $table->string('source');
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('receipt_number');
            $table->string('idempotency_key', 128);
            $table->timestamps();

            $table->unique(['family_id', 'receipt_number']);
            $table->unique(['family_id', 'idempotency_key']);
            $table->index(['family_id', 'paid_at']);
            $table->index(['family_id', 'family_membership_id', 'paid_at'], 'payment_batches_member_date_index');
            $table->index(['family_id', 'source', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_batches');
    }
};
