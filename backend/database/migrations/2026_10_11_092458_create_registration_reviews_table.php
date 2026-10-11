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
        Schema::create('registration_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registration_submission_id')
                ->constrained('registration_submissions')
                ->restrictOnDelete();

            $table->foreignId('reviewed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('decision', 30);
            $table->text('reason')->nullable();
            $table->timestamp('reviewed_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_reviews');
    }
};
