<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('source', 16)->default('panel');
            $table->unique(['workspace_id', 'user_id', 'day']);
            $table->index(['workspace_id', 'day']);
        });

        $workspaces = DB::table('workspaces')->pluck('id')->flip();
        $users = DB::table('users')->pluck('id')->flip();
        $rows = DB::table('activities')->whereNotNull('user_id')->whereNotNull('workspace_id')
            ->selectRaw("workspace_id, user_id, date(created_at) as day, 'activity' as source")
            ->distinct()->get()
            ->merge(Schema::hasTable('mcp_tool_calls') ? DB::table('mcp_tool_calls')->whereNotNull('user_id')->whereNotNull('workspace_id')
                ->selectRaw("workspace_id, user_id, date(created_at) as day, 'mcp' as source")
                ->distinct()->get() : collect())
            ->filter(fn ($row) => $workspaces->has($row->workspace_id) && $users->has($row->user_id));

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('workspace_visits')->insertOrIgnore($chunk->map(fn ($row) => (array) $row)->values()->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_visits');
    }
};
