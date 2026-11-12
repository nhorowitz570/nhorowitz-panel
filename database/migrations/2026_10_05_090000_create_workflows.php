<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_as')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('description', 280)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('trigger', 40)->default('manual');
            $table->json('graph');
            $table->string('webhook_hash', 64)->nullable()->unique();
            $table->text('webhook_token')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'trigger']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 12)->default('running');
            $table->json('trigger');
            $table->json('graph');
            $table->json('steps');
            $table->json('frontier');
            $table->json('logs');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('wake_at')->nullable()->index();

            $table->index(['workflow_id', 'id']);
            $table->index('state');
        });

        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workflow_node', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_run_id');
            $table->dropColumn('workflow_node');
        });
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflows');
    }
};
