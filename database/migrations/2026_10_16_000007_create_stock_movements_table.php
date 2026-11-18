<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every change to an ingredient's stock is logged here.
        // quantity is signed: positive adds stock, negative removes it.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);   // purchase | usage | adjustment | waste | void_return | opening
            $table->decimal('quantity', 12, 3);
            $table->decimal('total_cost', 12, 2)->nullable(); // amount paid, for purchases
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['ingredient_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
