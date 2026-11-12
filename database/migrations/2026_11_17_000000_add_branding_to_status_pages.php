<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['status_pages', 'workspace_status_pages'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('page_title', 120)->nullable();
                $table->string('icon_path')->nullable();
                $table->string('favicon_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['status_pages', 'workspace_status_pages'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['page_title', 'icon_path', 'favicon_path']);
            });
        }
    }
};
