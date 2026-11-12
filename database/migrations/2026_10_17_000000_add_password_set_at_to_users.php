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
            $table->timestamp('password_set_at')->nullable();
        });

        DB::table('users')->update(['password_set_at' => DB::raw('created_at')]);
        $socialSignups = DB::table('users')
            ->join('social_accounts', 'social_accounts.user_id', '=', 'users.id')
            ->get(['users.id', 'users.created_at as user_created', 'social_accounts.created_at as linked_at'])
            ->filter(fn ($row) => abs(strtotime((string) $row->linked_at) - strtotime((string) $row->user_created)) <= 60)
            ->pluck('id')
            ->unique();
        if ($socialSignups->isNotEmpty()) {
            DB::table('users')->whereIn('id', $socialSignups)->update(['password_set_at' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
