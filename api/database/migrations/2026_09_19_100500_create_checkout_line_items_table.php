<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('checkout_id')->constrained('checkouts')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_amount_minor');
            $table->unsignedInteger('amount_minor');
            $table->string('type', 32)->default('plan');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('checkout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_line_items');
    }
};
