<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->string('billing_key', 100)->unique();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('UNPAID');
            $table->unsignedSmallInteger('billing_year');
            $table->char('currency', 3)->default('MYR');
            $table->decimal('total', 12, 2);
            $table->timestamp('issued_at');
            $table->date('due_date');
            $table->timestamps();
            $table->index(['person_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
