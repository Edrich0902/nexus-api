<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_oauth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('state', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['provider', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_oauth_states');
    }
};
