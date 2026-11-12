<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('welcome')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->string('trial_plan')->nullable();
        });

        DB::table('workspaces')->update(['onboarded_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['welcome', 'onboarded_at', 'trial_plan']);
        });
    }
};
