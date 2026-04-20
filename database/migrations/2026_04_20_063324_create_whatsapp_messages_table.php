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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wa_message_id')->nullable()->unique();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->string('template_name')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('wa_timestamp')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['direction', 'created_at']);
            $table->index(['from', 'to']);
            $table->index(['family_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
