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
            $table->dropUnique('contributions_user_id_year_month_unique');
            $table->unique(['family_id', 'user_id', 'year', 'month'], 'contributions_family_user_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropUnique('contributions_family_user_period_unique');
            $table->unique(['user_id', 'year', 'month'], 'contributions_user_id_year_month_unique');
        });
    }
};
