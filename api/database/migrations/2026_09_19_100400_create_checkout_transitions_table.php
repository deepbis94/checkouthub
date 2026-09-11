<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('checkout_id')->constrained('checkouts')->cascadeOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('actor', 32);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('checkout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_transitions');
    }
};
