<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->date('due_date')->nullable()->after('expected_amount');

            $table->index(['family_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign(['family_id']);
            $table->dropIndex(['family_id', 'year', 'month']);
            $table->dropColumn(['family_id', 'due_date']);
        });
    }
};
