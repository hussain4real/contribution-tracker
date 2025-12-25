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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('member')->after('password');
            $table->string('category')->nullable()->after('role');
            $table->timestamp('archived_at')->nullable()->after('updated_at');

            $table->index('role');
            $table->index('category');
            $table->index('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['category']);
            $table->dropIndex(['archived_at']);

            $table->dropColumn(['role', 'category', 'archived_at']);
        });
    }
};
