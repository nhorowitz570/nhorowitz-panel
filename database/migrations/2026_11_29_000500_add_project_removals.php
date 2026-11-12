<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->timestamp('removing_at')->nullable()->after('refreshed_at');
            $table->unique(['project_id', 'pull_request']);
        });
        Schema::table('project_nodes', function (Blueprint $table) {
            $table->timestamp('removing_at')->nullable()->after('data_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_nodes', function (Blueprint $table) {
            $table->dropColumn('removing_at');
        });
        Schema::table('project_environments', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'pull_request']);
            $table->dropColumn('removing_at');
        });
    }
};
