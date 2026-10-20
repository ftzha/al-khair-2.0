<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('billing_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->dateTime('effective_at');
            $table->timestamps();
            $table->unique(['membership_id', 'billing_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_periods');
    }
};
