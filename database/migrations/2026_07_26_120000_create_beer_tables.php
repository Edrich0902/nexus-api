<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beer_styles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('family')->nullable()->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('beer_breweries', function (Blueprint $table) {
            $table->id();
            $table->string('obdb_id', 64)->nullable()->unique();
            $table->string('name');
            $table->string('brewery_type')->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('address_3')->nullable();
            $table->string('city')->nullable();
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone')->nullable();
            $table->string('website_url', 1024)->nullable();
            $table->string('source', 32)->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['name', 'country']);
        });

        Schema::create('beer_beers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beer_brewery_id')->nullable()->constrained('beer_breweries')->nullOnDelete();
            $table->foreignId('beer_style_id')->nullable()->constrained('beer_styles')->nullOnDelete();
            $table->string('name');
            $table->decimal('abv', 4, 1)->nullable();
            $table->unsignedSmallInteger('ibu')->nullable();
            $table->string('format')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beer_beers');
        Schema::dropIfExists('beer_breweries');
        Schema::dropIfExists('beer_styles');
    }
};
