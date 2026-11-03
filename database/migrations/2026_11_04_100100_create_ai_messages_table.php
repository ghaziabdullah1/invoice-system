<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('direction', 10);                 // in / out
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('status', 20)->default('received'); // received / draft / sent / rejected / failed
            $table->string('external_id', 100)->nullable()->unique();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
            $table->index(['status', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
