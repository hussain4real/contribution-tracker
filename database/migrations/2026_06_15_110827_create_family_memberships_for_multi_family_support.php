<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('category')->nullable();
            $table->foreignId('family_category_id')->nullable()->constrained('family_categories')->nullOnDelete();
            $table->timestamps();

            $table->unique(['family_id', 'user_id']);
            $table->index(['user_id', 'family_id']);
            $table->index(['family_id', 'role']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_family_id')
                ->nullable()
                ->after('family_id')
                ->constrained('families')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            INSERT INTO family_members (
                family_id,
                user_id,
                role,
                category,
                family_category_id,
                created_at,
                updated_at
            )
            SELECT
                family_id,
                id,
                role,
                category,
                family_category_id,
                COALESCE(created_at, CURRENT_TIMESTAMP),
                COALESCE(updated_at, CURRENT_TIMESTAMP)
            FROM users
            WHERE family_id IS NOT NULL
            ON CONFLICT (family_id, user_id) DO NOTHING
        SQL);

        DB::statement('UPDATE users SET current_family_id = family_id WHERE current_family_id IS NULL AND family_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_family_id');
        });

        Schema::dropIfExists('family_members');
    }
};
