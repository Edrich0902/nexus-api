<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('thumb_url', 1024)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('meal_catalog_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('meal_catalog_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('thumb_url', 1024)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('meal_catalog_meals', function (Blueprint $table) {
            $table->id();
            $table->string('mealdb_id', 32)->unique();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->string('area')->nullable()->index();
            $table->longText('instructions')->nullable();
            $table->string('thumb_url', 1024)->nullable();
            $table->json('tags')->nullable();
            $table->string('youtube_url', 1024)->nullable();
            $table->string('source_url', 1024)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('meal_catalog_meal_ingredient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_catalog_meal_id')->constrained('meal_catalog_meals')->cascadeOnDelete();
            $table->foreignId('meal_catalog_ingredient_id')->constrained('meal_catalog_ingredients')->cascadeOnDelete();
            $table->string('measure')->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->unique(['meal_catalog_meal_id', 'meal_catalog_ingredient_id', 'position'], 'meal_ingredient_pos_unique');
        });

        Schema::create('kitchen_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_catalog_meal_id')->constrained('meal_catalog_meals')->cascadeOnDelete();
            $table->string('source', 32)->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('cooked_count')->default(0);
            $table->date('last_cooked_on')->nullable();
            $table->boolean('is_favourite')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'meal_catalog_meal_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_recipes');
        Schema::dropIfExists('meal_catalog_meal_ingredient');
        Schema::dropIfExists('meal_catalog_meals');
        Schema::dropIfExists('meal_catalog_ingredients');
        Schema::dropIfExists('meal_catalog_areas');
        Schema::dropIfExists('meal_catalog_categories');
    }
};
