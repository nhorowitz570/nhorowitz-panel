<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_events', fn (Blueprint $table) => $table->string('country', 2)->nullable()->after('ip'));
    }

    public function down(): void
    {
        Schema::table('account_events', fn (Blueprint $table) => $table->dropColumn('country'));
    }
};
