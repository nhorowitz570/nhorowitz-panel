<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service_key', 300);
            $table->string('service', 200);
            $table->unsignedBigInteger('logged_at');
            $table->string('level', 5);
            $table->string('stream', 7);
            $table->text('message');

            $table->index(['machine_id', 'id']);
            $table->index(['machine_id', 'service', 'id']);
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
