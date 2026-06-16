<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
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

        DB::table('users')
            ->select(['id', 'family_id', 'role', 'category', 'family_category_id', 'created_at', 'updated_at'])
            ->whereNotNull('family_id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users): void {
                $timestamp = now();
                $memberships = $users
                    ->map(function (object $user) use ($timestamp): array {
                        $record = (array) $user;

                        return [
                            'family_id' => $record['family_id'],
                            'user_id' => $record['id'],
                            'role' => $record['role'],
                            'category' => $record['category'],
                            'family_category_id' => $record['family_category_id'],
                            'created_at' => $record['created_at'] ?? $timestamp,
                            'updated_at' => $record['updated_at'] ?? $timestamp,
                        ];
                    })
                    ->all();

                DB::table('family_members')->insertOrIgnore($memberships);
            });

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
