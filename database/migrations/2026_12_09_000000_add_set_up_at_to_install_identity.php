<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('install_identity', function (Blueprint $table) {
            $table->timestamp('set_up_at')->nullable();
        });

        if (DB::table('users')->exists()) {
            DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('install_identity', function (Blueprint $table) {
            $table->dropColumn('set_up_at');
        });
    }
};
