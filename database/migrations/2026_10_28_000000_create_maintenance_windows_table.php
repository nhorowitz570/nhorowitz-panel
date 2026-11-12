<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 200)->nullable();
            $table->boolean('public')->default(true);
            $table->string('action', 20)->default('none');
            $table->string('state', 20)->default('scheduled');
            $table->string('phase', 20)->nullable();
            $table->uuid('job_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome', 300)->nullable();
            $table->timestamps();

            $table->index(['state', 'starts_at']);
            $table->index(['machine_id', 'state']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('connected_at');
        });
        Schema::dropIfExists('maintenance_windows');
    }
};
