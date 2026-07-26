<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wine_catalog_wineries', function (Blueprint $table) {
            $table->id();
            $table->string('wineapi_id', 64)->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('wine_catalog_regions', function (Blueprint $table) {
            $table->id();
            $table->string('wineapi_id', 64)->nullable()->unique();
            $table->string('name');
            $table->string('country')->nullable();
            $table->timestamps();
        });

        Schema::create('wine_catalog_grapes', function (Blueprint $table) {
            $table->id();
            $table->string('wineapi_id', 64)->nullable()->unique();
            $table->string('name');
            $table->string('color')->nullable();
            $table->timestamps();
        });

        Schema::create('wine_catalog_wines', function (Blueprint $table) {
            $table->id();
            $table->uuid('wineapi_id')->unique();
            $table->foreignId('wine_catalog_winery_id')->nullable()->constrained('wine_catalog_wineries')->nullOnDelete();
            $table->foreignId('wine_catalog_region_id')->nullable()->constrained('wine_catalog_regions')->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('vintage')->nullable();
            $table->string('type')->nullable();
            $table->string('body')->nullable();
            $table->string('acidity')->nullable();
            $table->string('elaborate')->nullable();
            $table->string('classification')->nullable();
            $table->string('appellation')->nullable();
            $table->decimal('average_rating', 4, 2)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->decimal('alcohol_content', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('lwin_code')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->string('enrichment_status', 32)->default('pending')->index();
            $table->timestamp('enriched_at')->nullable();
            $table->unsignedTinyInteger('enrichment_attempts')->default(0);
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('wine_catalog_wine_grape', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wine_catalog_wine_id')->constrained('wine_catalog_wines')->cascadeOnDelete();
            $table->foreignId('wine_catalog_grape_id')->constrained('wine_catalog_grapes')->cascadeOnDelete();
            $table->unique(['wine_catalog_wine_id', 'wine_catalog_grape_id'], 'wine_catalog_wine_grape_unique');
        });

        Schema::create('wine_catalog_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wine_catalog_wine_id')->constrained('wine_catalog_wines')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('score_text')->nullable();
            $table->string('reviewer')->nullable();
            $table->date('review_date')->nullable();
            $table->timestamps();
        });

        Schema::create('wine_catalog_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wine_catalog_wine_id')->constrained('wine_catalog_wines')->cascadeOnDelete();
            $table->string('merchant_name')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('url', 1024)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wine_catalog_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wine_catalog_wine_id')->constrained('wine_catalog_wines')->cascadeOnDelete();
            $table->string('food');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wine_catalog_pairings');
        Schema::dropIfExists('wine_catalog_prices');
        Schema::dropIfExists('wine_catalog_scores');
        Schema::dropIfExists('wine_catalog_wine_grape');
        Schema::dropIfExists('wine_catalog_wines');
        Schema::dropIfExists('wine_catalog_grapes');
        Schema::dropIfExists('wine_catalog_regions');
        Schema::dropIfExists('wine_catalog_wineries');
    }
};
