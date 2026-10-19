<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->onDelete('restrict');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('TRY');
            $table->enum('payment_method', ['cash', 'credit_card', 'bank_transfer', 'insurance'])->default('cash');
            $table->string('receipt_reference', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('received_by', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
