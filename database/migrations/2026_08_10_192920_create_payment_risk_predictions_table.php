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
        Schema::create('payment_risk_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_membership_id')->constrained('family_members')->cascadeOnDelete();
            $table->foreignId('contribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_risk_model_version_id')
                ->constrained('payment_risk_model_versions')
                ->restrictOnDelete();
            $table->timestamp('cutoff_at');
            $table->double('probability')->nullable();
            $table->string('advisory_band', 30)->nullable();
            $table->string('history_tier', 30);
            $table->unsignedSmallInteger('history_periods');
            $table->char('feature_snapshot_hash', 64);
            $table->json('factors');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['contribution_id', 'payment_risk_model_version_id', 'cutoff_at'],
                'payment_risk_prediction_identity_unique',
            );
            $table->index(['family_id', 'history_tier', 'generated_at'], 'payment_risk_family_tier_index');
            $table->index(['family_membership_id', 'generated_at'], 'payment_risk_membership_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_risk_predictions');
    }
};
