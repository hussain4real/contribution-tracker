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
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['family_id', 'name']);
        });
        Schema::create('expense_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['family_id', 'expense_category_id', 'month']);
        });
        $sqlite = Schema::getConnection()->getSchemaGrammar() instanceof SQLiteGrammar;
        foreach ([
            'expenses' => 'ALTER TABLE "expenses" ADD COLUMN "expense_category_id" INTEGER REFERENCES "expense_categories"("id") ON DELETE RESTRICT',
            'expense_requests' => 'ALTER TABLE "expense_requests" ADD COLUMN "expense_category_id" INTEGER REFERENCES "expense_categories"("id") ON DELETE RESTRICT',
        ] as $name => $statement) {
            if ($sqlite) {
                // Inline references preserve D1 guard triggers and dependent records without rebuilding the table.
                Schema::getConnection()->statement($statement);
            }
            Schema::table($name, function (Blueprint $table) use ($sqlite): void {
                if (! $sqlite) {
                    $table->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete();
                }
                $table->index(['family_id', 'spent_at', 'expense_category_id']);
            });
        }
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getSchemaGrammar() instanceof SQLiteGrammar;
        foreach (['expenses', 'expense_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($sqlite): void {
                $table->dropIndex(['family_id', 'spent_at', 'expense_category_id']);
                if ($sqlite) {
                    $table->dropColumn('expense_category_id');
                } else {
                    $table->dropConstrainedForeignId('expense_category_id');
                }
            });
        }
        Schema::dropIfExists('expense_budgets');
        Schema::dropIfExists('expense_categories');
    }
};
