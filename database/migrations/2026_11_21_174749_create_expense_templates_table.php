<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('description');
            $table->unsignedTinyInteger('day_of_month');
            $table->date('next_run_on')->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->unique(['family_id', 'idempotency_key']);
            $table->index(['next_run_on', 'family_id']);
            $table->index('expense_category_id');
        });

        $sqlite = Schema::getConnection()->getSchemaGrammar() instanceof SQLiteGrammar;
        if ($sqlite) {
            // Keep the existing request table and its D1 guards intact.
            Schema::getConnection()->statement('ALTER TABLE "expense_requests" ADD COLUMN "expense_template_id" INTEGER REFERENCES "expense_templates"("id") ON DELETE RESTRICT');
        }
        Schema::table('expense_requests', function (Blueprint $table) use ($sqlite): void {
            if (! $sqlite) {
                $table->foreignId('expense_template_id')->nullable()->constrained()->restrictOnDelete();
            }
            $table->date('template_month')->nullable();
            $table->unique(['expense_template_id', 'template_month']);
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getSchemaGrammar() instanceof SQLiteGrammar;
        Schema::table('expense_requests', function (Blueprint $table) use ($sqlite): void {
            $table->dropUnique(['expense_template_id', 'template_month']);
            $table->dropColumn('template_month');
            if ($sqlite) {
                $table->dropColumn('expense_template_id');
            } else {
                $table->dropConstrainedForeignId('expense_template_id');
            }
        });
        Schema::dropIfExists('expense_templates');
    }
};
