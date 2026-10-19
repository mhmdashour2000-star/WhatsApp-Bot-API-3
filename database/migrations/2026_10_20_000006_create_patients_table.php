<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('primary_phone', 30)->index();
            $table->string('full_name', 191);
            $table->string('id_number', 50)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female', 'unspecified'])->default('unspecified');
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason', 255)->nullable();
            $table->boolean('kvkk_consent_accepted')->default(false);
            $table->timestamp('kvkk_consent_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
