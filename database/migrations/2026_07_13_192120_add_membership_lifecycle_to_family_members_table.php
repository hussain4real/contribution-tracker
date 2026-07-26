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
        Schema::table('family_members', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('user_id');
            $table->timestamp('archived_at')->nullable()->after('family_category_id');
            $table->foreignId('archived_by')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('archive_reason')->nullable()->after('archived_by');

            $table->index(['family_id', 'archived_at']);
            $table->index(['family_id', 'family_category_id', 'archived_at'], 'family_members_category_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropIndex(['family_id', 'archived_at']);
            $table->dropIndex('family_members_category_active_index');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['display_name', 'archived_at', 'archive_reason']);
        });
    }
};
