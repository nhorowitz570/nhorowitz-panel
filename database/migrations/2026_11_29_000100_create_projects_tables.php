<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 12)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::create('project_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('host');
            $table->unsignedSmallInteger('listen_port');
            $table->string('public_key', 64)->nullable();
            $table->string('state', 16)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'machine_id']);
            $table->unique(['project_id', 'host']);
        });

        Schema::create('project_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            $table->string('kind', 16);
            $table->string('template', 63)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'machine_id', 'name']);
        });

        Schema::create('project_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_node_id')->constrained('project_nodes')->cascadeOnDelete();
            $table->foreignId('resource_node_id')->constrained('project_nodes')->cascadeOnDelete();
            $table->json('env')->nullable();
            $table->string('state', 16)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['app_node_id', 'resource_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_links');
        Schema::dropIfExists('project_nodes');
        Schema::dropIfExists('project_machines');
        Schema::dropIfExists('projects');
    }
};
