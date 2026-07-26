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
        Schema::table('family_invitations', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('delivery_method')->default('email')->after('email');
            $table->string('whatsapp_phone')->nullable()->after('delivery_method');

            $table->index(['family_id', 'whatsapp_phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('family_invitations', function (Blueprint $table) {
            $table->dropIndex(['family_id', 'whatsapp_phone']);
            $table->dropColumn(['delivery_method', 'whatsapp_phone']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
