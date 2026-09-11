<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('checkout_id')->nullable()->constrained('checkouts')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email');
            $table->string('status', 32)->default('pending');
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_renewal_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'next_renewal_at']);
            $table->index(['status', 'next_renewal_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
