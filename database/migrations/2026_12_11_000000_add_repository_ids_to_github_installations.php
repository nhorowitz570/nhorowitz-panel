<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_installations', function (Blueprint $table) {
            $table->json('repository_ids')->nullable()->after('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('github_installations', function (Blueprint $table) {
            $table->dropColumn('repository_ids');
        });
    }
};
