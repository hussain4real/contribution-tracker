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
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_artifact_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('channel', 20);
            $table->string('recipient');
            $table->string('status', 20);
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['report_schedule_id', 'period_start', 'period_end']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
    }
};
