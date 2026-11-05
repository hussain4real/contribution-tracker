<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('description');
            $table->date('spent_at');
            $table->string('status')->default('submitted');
            $table->unsignedSmallInteger('required_approvals');
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->unique(['family_id', 'idempotency_key']);
            $table->index(['family_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_requests');
    }
};
