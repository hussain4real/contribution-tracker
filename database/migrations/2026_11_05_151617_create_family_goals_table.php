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
        Schema::create('family_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->enum('measure', ['net_savings', 'contributions'])->default('net_savings');
            $table->unsignedBigInteger('target_amount');
            $table->date('starts_on');
            $table->date('deadline');
            $table->timestamp('archived_at')->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->unique(['family_id', 'idempotency_key']);
            $table->index(['family_id', 'archived_at', 'deadline']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_goals');
    }
};
