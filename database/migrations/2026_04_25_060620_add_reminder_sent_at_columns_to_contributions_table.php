<?php

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
            $table->timestamp('reminder_sent_at')->nullable()->after('due_date');
            $table->timestamp('follow_up_sent_at')->nullable()->after('reminder_sent_at');

            $table->index(['year', 'month', 'reminder_sent_at'], 'contributions_month_reminder_sent_at_index');
            $table->index(['year', 'month', 'follow_up_sent_at'], 'contributions_month_follow_up_sent_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropIndex('contributions_month_reminder_sent_at_index');
            $table->dropIndex('contributions_month_follow_up_sent_at_index');
            $table->dropColumn(['reminder_sent_at', 'follow_up_sent_at']);
        });
    }
};
