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
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_phone')->nullable()->after('email');
            $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp_phone');

            $table->index('whatsapp_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_phone']);
            $table->dropColumn(['whatsapp_phone', 'whatsapp_verified_at']);
        });
    }
};
