<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spirit_spirits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('producer')->nullable();
            $table->string('category')->nullable();
            $table->string('age_statement')->nullable();
            $table->decimal('abv', 4, 1)->nullable();
            $table->string('region')->nullable();
            $table->string('country')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->string('analysis_status', 32)->default('none');
            $table->timestamp('analysed_at')->nullable();
            $table->string('analysis_model', 128)->nullable();
            $table->string('analysis_prompt_version', 32)->nullable();
            $table->string('analysis_error', 512)->nullable();
            $table->json('ai_analysis')->nullable();
            $table->foreignId('analysis_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('media_public_id')->nullable();
            $table->string('media_url', 1024)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index('analysis_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spirit_spirits');
    }
};
