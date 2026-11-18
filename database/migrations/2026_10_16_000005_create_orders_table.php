<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('total_cost', 12, 2)->default(0);   // recipe cost at time of sale
            $table->string('payment_method', 20)->default('cash');
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->default(0);
            $table->string('status', 20)->default('completed'); // completed | voided
            $table->string('customer_note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
