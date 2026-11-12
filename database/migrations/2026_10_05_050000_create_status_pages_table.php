<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('public')->default(true);
            $table->string('title', 80)->nullable();
            $table->string('description', 280)->nullable();
            $table->json('services')->nullable();
            $table->boolean('include_new')->default(true);
            $table->boolean('show_uptime')->default(true);
            $table->string('notice', 500)->nullable();
            $table->string('notice_kind', 20)->nullable();
            $table->json('uptime')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');
    }
};
