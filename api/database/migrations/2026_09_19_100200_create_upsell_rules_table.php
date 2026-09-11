<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('trigger_conditions')->nullable();
            $table->json('eligibility_rules')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('display_slot', 64)->default('post_purchase');
            $table->unsignedInteger('amount_minor')->default(0);
            $table->boolean('is_stackable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['offer_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_rules');
    }
};
