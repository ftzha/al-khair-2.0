<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 100)->unique();
            $table->string('type', 30);
            $table->dateTime('effective_at');
            $table->dateTime('recorded_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['membership_id', 'effective_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_events');
    }
};
