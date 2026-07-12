<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id');
            $table->string('owner_login');
            $table->string('name');
            $table->string('full_name');
            $table->boolean('private')->default(false);
            $table->string('default_branch')->nullable();
            $table->string('html_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->string('language')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'github_id']);
            $table->index(['user_id', 'pushed_at']);
            $table->index(['user_id', 'full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_repos');
    }
};
