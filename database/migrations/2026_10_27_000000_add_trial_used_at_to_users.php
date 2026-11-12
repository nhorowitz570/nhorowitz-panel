<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_used_at')->nullable();
        });

        DB::table('users')->whereNull('trial_used_at')->update([
            'trial_used_at' => DB::raw('(select min(workspaces.created_at) from workspaces where workspaces.owner_id = users.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_used_at');
        });
    }
};
