<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_wines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wine_catalog_wine_id')->nullable()->constrained('wine_catalog_wines')->nullOnDelete();
            $table->string('producer_name')->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('vintage')->nullable();
            $table->string('wine_type')->nullable();
            $table->string('region_name')->nullable();
            $table->string('country')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->string('match_status', 32)->default('unmatched')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'match_status']);
        });

        Schema::create('cellar_wine_tastings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cellar_wine_id')->constrained('cellar_wines')->cascadeOnDelete();
            $table->date('tasted_on');
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->string('occasion')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();

            $table->index(['cellar_wine_id', 'tasted_on']);
            $table->index(['user_id', 'tasted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_wine_tastings');
        Schema::dropIfExists('cellar_wines');
    }
};
