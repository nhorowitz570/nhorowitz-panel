<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_machines', function (Blueprint $table) {
            $table->unsignedInteger('listen_port')->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_machines', function (Blueprint $table) {
            $table->unsignedSmallInteger('listen_port')->change();
        });
    }
};
