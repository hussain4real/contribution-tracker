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
        Schema::table('contributions', function (Blueprint $table) {
            $table->foreignId('family_category_id')
                ->nullable()
                ->after('family_id')
                ->constrained('family_categories')
                ->nullOnDelete();
            $table->string('category_name')->nullable()->after('family_category_id');
            $table->string('category_slug')->nullable()->after('category_name');
            $table->integer('category_amount')->nullable()->after('category_slug');

            $table->index(['family_id', 'family_category_id', 'year', 'month'], 'contributions_category_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropIndex('contributions_category_period_index');
            $table->dropConstrainedForeignId('family_category_id');
            $table->dropColumn(['category_name', 'category_slug', 'category_amount']);
        });
    }
};
