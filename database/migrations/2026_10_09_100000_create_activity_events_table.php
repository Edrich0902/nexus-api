<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 32);
            $table->string('type', 48);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at', 'id']);
            $table->index(['user_id', 'module', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('image_palettes', function (Blueprint $table): void {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->string('url', 1024);
            $table->string('status', 16)->default('pending');
            $table->json('palette')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_palettes');
        Schema::dropIfExists('activity_events');
    }
};
