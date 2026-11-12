<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_log_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('driver', 10)->default('pgsql');
            $table->string('host', 255);
            $table->unsignedInteger('port')->default(5432);
            $table->string('database', 120);
            $table->string('username', 120);
            $table->text('password');
            $table->boolean('enabled')->default(false);
            $table->boolean('prune')->default(true);
            $table->string('status', 12)->default('ok');
            $table->text('last_error')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_log_stores');
    }
};
