<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_rate_id')->constrained()->restrictOnDelete();
            $table->string('fee_code', 30);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['invoice_id', 'fee_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
