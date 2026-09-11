<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform', 32); // shopify|bigcommerce
            $table->string('api_key_prefix', 16);
            $table->char('api_key_hash', 64)->unique();
            $table->char('currency', 3)->default('USD');
            $table->string('webhook_secret')->nullable();
            $table->string('outbound_webhook_url')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
