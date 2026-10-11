<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();

            // Personal information
            $table->string('full_name');
            $table->date('date_of_birth')->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->text('address')->nullable();

            // Identity information
            $table->text('identity_number');
            $table->char('identity_lookup_hash', 64)->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
