<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->boolean('provider_manual')->default(false);
            $table->string('region', 60)->nullable();
            $table->boolean('region_manual')->default(false);
            $table->string('tier', 60)->nullable();
            $table->json('labels')->nullable();
        });

        Schema::table('machine_policies', function (Blueprint $table) {
            $table->string('pinned_version', 40)->nullable();
            $table->json('applies_to')->nullable();
        });

        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->json('metadata')->nullable();
        });

        DB::table('machines')->whereNotNull('facts')->orderBy('id')->chunkById(200, function ($machines) {
            foreach ($machines as $machine) {
                $region = json_decode((string) $machine->facts, true)['hosting']['region'] ?? null;
                if (is_string($region) && $region !== '') {
                    DB::table('machines')->where('id', $machine->id)->update(['region' => mb_substr($region, 0, 60)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
        Schema::table('machine_policies', function (Blueprint $table) {
            $table->dropColumn(['pinned_version', 'applies_to']);
        });
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['provider_manual', 'region', 'region_manual', 'tier', 'labels']);
        });
    }
};
