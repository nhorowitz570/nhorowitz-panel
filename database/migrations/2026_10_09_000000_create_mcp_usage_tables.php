<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->integer('mcp_call_limit_override')->nullable()->after('machine_limit_override');
        });

        Schema::create('mcp_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedInteger('calls')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'period']);
        });

        Schema::create('mcp_call_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedInteger('calls');
            $table->unsignedInteger('amount')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_packs');
        Schema::dropIfExists('mcp_usage');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('mcp_call_limit_override');
        });
    }
};
