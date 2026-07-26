<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'users',
            'cellar_wines',
            'wine_catalog_wines',
            'kitchen_recipes',
            'beer_beers',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->foreignId('media_asset_id')
                    ->nullable()
                    ->after($tableName === 'users' ? 'password' : 'id')
                    ->constrained('media_assets')
                    ->nullOnDelete();
                $table->string('media_public_id')->nullable()->after('media_asset_id');
                $table->string('media_url', 1024)->nullable()->after('media_public_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('media_asset_id');
                $table->dropColumn(['media_public_id', 'media_url']);
            });
        }
    }
};
