<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cellar_wines', 'beer_beers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'analysis_status')) {
                    $table->string('analysis_status', 32)->default('none')->after('notes');
                    $table->timestamp('analysed_at')->nullable()->after('analysis_status');
                    $table->string('analysis_model', 128)->nullable()->after('analysed_at');
                    $table->string('analysis_prompt_version', 32)->nullable()->after('analysis_model');
                    $table->string('analysis_error', 512)->nullable()->after('analysis_prompt_version');
                    $table->json('ai_analysis')->nullable()->after('analysis_error');
                    $table->foreignId('analysis_media_asset_id')->nullable()->after('ai_analysis')
                        ->constrained('media_assets')->nullOnDelete();
                    $table->index('analysis_status');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['cellar_wines', 'beer_beers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'analysis_media_asset_id')) {
                    $table->dropConstrainedForeignId('analysis_media_asset_id');
                }
                $cols = [
                    'analysis_status',
                    'analysed_at',
                    'analysis_model',
                    'analysis_prompt_version',
                    'analysis_error',
                    'ai_analysis',
                ];
                foreach ($cols as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
