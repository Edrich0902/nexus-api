<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->boolean('starred')->default(false)->after('language');
            $table->index(['user_id', 'starred']);
        });
    }

    public function down(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'starred']);
            $table->dropColumn('starred');
        });
    }
};
