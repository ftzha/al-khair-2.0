<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('initial_invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('activation_payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('activated_at');
            $table->date('activated_on');
            $table->date('waiting_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
