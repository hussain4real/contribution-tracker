<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_request_id')->constrained()->cascadeOnDelete();
            // Preserve the distinct historical approver identity after account deletion.
            $table->unsignedBigInteger('user_id');
            $table->string('decision');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['expense_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approvals');
    }
};
