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
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('gross_amount_kobo')->nullable();
            $table->unsignedBigInteger('estimated_fee_kobo')->nullable();
            $table->unsignedBigInteger('actual_fee_kobo')->nullable();
            $table->unsignedBigInteger('settled_amount_kobo')->nullable();
            $table->string('fee_policy')->default('payer_pays');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'gross_amount_kobo',
                'estimated_fee_kobo',
                'actual_fee_kobo',
                'settled_amount_kobo',
                'fee_policy',
            ]);
        });
    }
};
