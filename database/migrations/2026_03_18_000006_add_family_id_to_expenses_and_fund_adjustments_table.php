<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            $table->index(['family_id', 'spent_at']);
        });

        Schema::table('fund_adjustments', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            $table->index(['family_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['family_id']);
            $table->dropIndex(['family_id', 'spent_at']);
            $table->dropColumn('family_id');
        });

        Schema::table('fund_adjustments', function (Blueprint $table) {
            $table->dropForeign(['family_id']);
            $table->dropIndex(['family_id', 'recorded_at']);
            $table->dropColumn('family_id');
        });
    }
};
