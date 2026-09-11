<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 32)->default('pending');
            $table->json('offer_snapshot');
            $table->char('currency', 3);
            $table->unsignedInteger('subtotal_minor')->default(0);
            $table->unsignedInteger('discount_minor')->default(0);
            $table->unsignedInteger('total_minor')->default(0);
            $table->string('discount_code')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'state']);
            $table->index('expires_at');
            $table->unique(['store_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
