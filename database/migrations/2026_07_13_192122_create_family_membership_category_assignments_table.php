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
        Schema::create('family_membership_category_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_membership_id')->constrained('family_members')->cascadeOnDelete();
            $table->foreignId('family_category_id')->constrained('family_categories')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category_name');
            $table->string('category_slug');
            $table->integer('monthly_amount');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['family_membership_id', 'effective_from'], 'membership_category_effective_unique');
            $table->index(
                ['family_membership_id', 'effective_from', 'effective_until'],
                'membership_category_period_index',
            );
            $table->index(['family_category_id', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_membership_category_assignments');
    }
};
