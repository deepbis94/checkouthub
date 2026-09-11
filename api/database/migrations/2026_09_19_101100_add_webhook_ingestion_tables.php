<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('platform_identifier')->nullable()->after('platform');
            $table->unique(['platform', 'platform_identifier']);
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'topic']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider_customer_id');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'provider_customer_id']);
            $table->index(['store_id', 'email']);
        });

        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider_order_id');
            $table->foreignUuid('checkout_id')->nullable()->constrained('checkouts')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('status', 32)->nullable();
            $table->unsignedInteger('total_minor')->default(0);
            $table->char('currency', 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'provider_order_id']);
            $table->index('checkout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_orders');
        Schema::dropIfExists('customers');

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['platform', 'platform_identifier']);
            $table->dropColumn('platform_identifier');
        });
    }
};
