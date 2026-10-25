<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('processed_whatsapp_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('message_id')->unique();
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_whatsapp_messages');
    }
};
