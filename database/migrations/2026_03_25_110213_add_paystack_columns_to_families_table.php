<?php

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
            $table->string('bank_code')->nullable()->after('account_number');
            $table->string('paystack_subaccount_code')->nullable()->after('bank_code');
            $table->string('paystack_customer_code')->nullable()->after('paystack_subaccount_code');
            $table->string('paystack_subscription_code')->nullable()->after('paystack_customer_code');
            $table->string('subscription_status')->default('free')->after('paystack_subscription_code');
            $table->timestamp('current_period_end')->nullable()->after('subscription_status');
            $table->foreignId('platform_plan_id')->nullable()->after('current_period_end')->constrained('platform_plans')->nullOnDelete();

            $table->index('paystack_customer_code');
            $table->index('paystack_subscription_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropIndex(['paystack_customer_code']);
            $table->dropIndex(['paystack_subscription_code']);
            $table->dropForeign(['platform_plan_id']);
            $table->dropColumn([
                'bank_code',
                'paystack_subaccount_code',
                'paystack_customer_code',
                'paystack_subscription_code',
                'subscription_status',
                'current_period_end',
                'platform_plan_id',
            ]);
        });
    }
};
