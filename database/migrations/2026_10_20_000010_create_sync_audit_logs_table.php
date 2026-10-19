<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_name', 60);
            $table->unsignedBigInteger('entity_id');
            $table->enum('action_type', ['insert', 'update', 'delete', 'sync_conflict']);
            $table->string('changed_by', 100);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedInteger('client_version')->nullable();
            $table->unsignedInteger('server_version')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_name', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_audit_logs');
    }
};
