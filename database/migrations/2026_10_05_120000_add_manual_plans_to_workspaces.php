<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('plan_source', 16)->nullable()->after('billing_interval');
            $table->timestamp('manual_plan_until')->nullable()->after('plan_source');
            $table->integer('machine_limit_override')->nullable()->after('manual_plan_until');
            $table->string('plan_note', 200)->nullable()->after('machine_limit_override');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['plan_source', 'manual_plan_until', 'machine_limit_override', 'plan_note']);
        });
    }
};
