<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 32);
            $table->string('slug');
            $table->string('name')->nullable();
            $table->string('owner_login')->nullable();
            $table->string('html_url')->nullable();
            $table->string('client_id');
            $table->text('client_secret');
            $table->text('private_key');
            $table->text('webhook_secret')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_app_settings');
    }
};
