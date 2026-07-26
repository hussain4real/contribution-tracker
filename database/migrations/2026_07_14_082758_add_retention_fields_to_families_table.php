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
        Schema::table('families', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('suspended_at');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable()->after('archived_by');
            $table->timestamp('purge_after')->nullable()->after('archive_reason');
            $table->timestamp('legal_hold_at')->nullable()->after('purge_after');
            $table->foreignId('legal_hold_by')->nullable()->after('legal_hold_at')->constrained('users')->nullOnDelete();
            $table->text('legal_hold_reason')->nullable()->after('legal_hold_by');

            $table->index(['archived_at', 'purge_after']);
            $table->index(['purge_after', 'legal_hold_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropIndex(['archived_at', 'purge_after']);
            $table->dropIndex(['purge_after', 'legal_hold_at']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropConstrainedForeignId('legal_hold_by');
            $table->dropColumn([
                'archived_at',
                'archive_reason',
                'purge_after',
                'legal_hold_at',
                'legal_hold_reason',
            ]);
        });
    }
};
