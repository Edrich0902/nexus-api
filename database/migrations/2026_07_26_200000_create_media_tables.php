<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('public_id')->unique();
            $table->string('asset_id')->nullable()->index();
            $table->string('folder')->nullable();
            $table->string('collection', 64)->index();
            $table->string('secure_url', 1024);
            $table->string('format', 32)->nullable();
            $table->string('resource_type', 32)->default('image');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->string('etag')->nullable()->index();
            $table->string('alt_text')->nullable();
            $table->string('source', 32)->default('upload')->index();
            $table->string('source_provider', 64)->nullable();
            $table->string('source_ref')->nullable();
            $table->json('source_meta')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'collection', 'created_at']);
            $table->index(['source_provider', 'source_ref']);
        });

        Schema::create('mediables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('role', 64)->default('cover');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['media_asset_id', 'mediable_type', 'mediable_id', 'role'], 'mediables_unique_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediables');
        Schema::dropIfExists('media_assets');
    }
};
