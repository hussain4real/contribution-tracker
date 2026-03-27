<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `plan` column is superseded by `platform_plan_id` + `subscription_status`.
     * This migration ensures no non-default data is lost before dropping.
     */
    public function up(): void
    {
        $nonDefaultCount = DB::table('families')
            ->where('plan', '!=', 'free')
            ->count();

        if ($nonDefaultCount > 0) {
            throw new RuntimeException(
                "Cannot drop `plan` column: {$nonDefaultCount} families have non-default values. "
                .'Migrate these to `platform_plan_id` / `subscription_status` first.'
            );
        }

        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('plan')->default('free')->after('due_day');
        });
    }
};
