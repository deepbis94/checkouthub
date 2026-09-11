<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('checkout_id')->constrained('checkouts')->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->char('request_hash', 64);
            $table->char('response_hash', 64);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('outcome', 32);
            $table->string('failover_reason')->nullable();
            $table->string('charge_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('checkout_id');
            $table->index(['gateway', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_events');
    }
};
