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
        Schema::create('payment_risk_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 100)->unique();
            $table->string('schema_version', 100);
            $table->string('artifact_disk', 40)->default('local');
            $table->string('artifact_path');
            $table->char('artifact_sha256', 64)->unique();
            $table->json('feature_schema');
            $table->double('threshold');
            $table->date('training_window_start');
            $table->date('training_window_end');
            $table->json('dataset_summary');
            $table->json('metrics');
            $table->boolean('activation_eligible')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('trained_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'activation_eligible']);
            $table->index('training_window_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_risk_model_versions');
    }
};
