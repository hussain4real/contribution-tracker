<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_category_id')->nullable()->after('category')->constrained('family_categories')->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('role');

            $table->index('family_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['family_id']);
            $table->dropForeign(['family_category_id']);
            $table->dropColumn(['family_id', 'family_category_id', 'is_super_admin']);
        });
    }
};
