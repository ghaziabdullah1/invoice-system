<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('contact', 100);
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('status', 20)->default('bot');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'contact']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
