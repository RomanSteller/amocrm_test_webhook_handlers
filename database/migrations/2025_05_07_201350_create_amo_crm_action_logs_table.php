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
        Schema::create('amo_crm_action_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('entity_type', ['lead', 'contact']);
            $table->unsignedBigInteger('entity_id');
            $table->enum('action_type', ['added', 'updated']);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amo_crm_action_logs');
    }
};
