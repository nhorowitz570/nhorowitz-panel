<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_counts', function (Blueprint $table) {
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('minute');
            $table->string('service', 200);
            $table->string('level', 10);
            $table->unsignedInteger('n');

            $table->unique(['machine_id', 'minute', 'service', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_counts');
    }
};
