<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('monthly_amount');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['family_id', 'slug']);
            $table->index('family_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_categories');
    }
};
