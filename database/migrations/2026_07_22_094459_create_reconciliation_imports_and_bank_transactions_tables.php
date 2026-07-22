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
        Schema::create('reconciliation_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('file_fingerprint', 64);
            $table->string('delimiter', 1)->default(',');
            $table->json('headers');
            $table->json('preview_rows');
            $table->json('mapping')->nullable();
            $table->string('status')->default('previewed');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['family_id', 'file_fingerprint']);
            $table->index(['family_id', 'status', 'created_at']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reconciliation_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('direction');
            $table->date('transacted_at');
            $table->unsignedBigInteger('amount');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('source_account')->nullable();
            $table->json('raw_data');
            $table->char('row_fingerprint', 64);
            $table->string('status')->default('unmatched');
            $table->text('ignored_reason')->nullable();
            $table->text('disputed_reason')->nullable();
            $table->timestamps();

            $table->unique(['family_id', 'row_fingerprint']);
            $table->index(['family_id', 'status', 'transacted_at']);
            $table->index(['family_id', 'direction', 'amount']);
            $table->index(['family_id', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('reconciliation_imports');
    }
};
