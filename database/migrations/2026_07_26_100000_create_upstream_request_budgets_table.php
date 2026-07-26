<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upstream_request_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->date('usage_date');
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('daily_limit');
            $table->timestamps();

            $table->unique(['provider', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upstream_request_budgets');
    }
};
