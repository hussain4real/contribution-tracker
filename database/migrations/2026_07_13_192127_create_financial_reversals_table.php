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
        Schema::create('financial_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('reversible_type');
            $table->unsignedBigInteger('reversible_id');
            $table->string('replacement_type')->nullable();
            $table->unsignedBigInteger('replacement_id')->nullable();
            $table->text('reason');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['reversible_type', 'reversible_id']);
            $table->index(['family_id', 'request_id']);
            $table->index(['family_id', 'created_at']);
            $table->index(['replacement_type', 'replacement_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_reversals');
    }
};
