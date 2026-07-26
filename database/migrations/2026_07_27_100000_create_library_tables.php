<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_catalog_books', function (Blueprint $table) {
            $table->id();
            $table->string('ol_work_key', 64)->unique();
            $table->string('ol_edition_key', 64)->nullable()->index();
            $table->string('title');
            $table->json('authors')->nullable();
            $table->string('isbn_10', 16)->nullable()->index();
            $table->string('isbn_13', 16)->nullable()->index();
            $table->unsignedSmallInteger('publish_year')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('cover_i')->nullable();
            $table->string('cover_url', 1024)->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('media_public_id')->nullable();
            $table->string('media_url', 1024)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index('title');
        });

        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_catalog_book_id')
                ->nullable()
                ->constrained('library_catalog_books')
                ->nullOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('media_public_id')->nullable();
            $table->string('media_url', 1024)->nullable();
            $table->string('title');
            $table->string('authors')->nullable();
            $table->string('isbn', 32)->nullable();
            $table->string('status', 16)->default('want')->index();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->date('started_at')->nullable();
            $table->date('finished_at')->nullable();
            $table->string('match_status', 32)->default('unmatched')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_books');
        Schema::dropIfExists('library_catalog_books');
    }
};
