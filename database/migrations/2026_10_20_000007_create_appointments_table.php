<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 30)->unique();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('restrict');
            $table->foreignId('doctor_id')->constrained('doctors')->onDelete('restrict');
            $table->date('appointment_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('status', [
                'holding',
                'confirmed',
                'completed',
                'cancelled_by_patient',
                'cancelled_by_clinic',
                'no_show'
            ])->default('holding');
            $table->timestamp('holding_expires_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('attended')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->enum('source', ['whatsapp', 'secretary_access', 'phone_call', 'walk_in'])->default('whatsapp');
            $table->timestamps();

            $table->index(['doctor_id', 'appointment_date', 'start_time'], 'idx_doc_date_time');
            $table->index(['patient_id', 'appointment_date'], 'idx_patient_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
