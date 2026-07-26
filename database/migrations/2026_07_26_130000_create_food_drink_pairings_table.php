<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_drink_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('drinkable_type');
            $table->unsignedBigInteger('drinkable_id');
            $table->foreignId('kitchen_recipe_id')->constrained('kitchen_recipes')->cascadeOnDelete();
            $table->string('verdict', 16)->default('good');
            $table->text('notes')->nullable();
            $table->string('source', 16)->default('manual');
            $table->timestamps();

            $table->unique(
                ['user_id', 'drinkable_type', 'drinkable_id', 'kitchen_recipe_id'],
                'food_drink_pairings_unique',
            );
            $table->index(['drinkable_type', 'drinkable_id']);
            $table->index(['user_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_drink_pairings');
    }
};
