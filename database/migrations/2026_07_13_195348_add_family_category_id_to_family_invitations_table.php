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
        Schema::table('family_invitations', function (Blueprint $table) {
            $table->foreignId('family_category_id')
                ->nullable()
                ->after('role')
                ->constrained('family_categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('family_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('family_category_id');
        });
    }
};
