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
        Schema::create('registration_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registration_id')
                ->constrained('registrations')
                ->restrictOnDelete();

            $table->unsignedInteger('revision');

            $table->json('snapshot');

            $table->foreignId('submitted_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('submitted_at');

            $table->timestamps();

            $table->unique(['registration_id', 'revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_submissions');
    }
};
