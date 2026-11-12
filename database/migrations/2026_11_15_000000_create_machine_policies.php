<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('is_default')->default(false);
            $table->boolean('discovery')->default(true);
            $table->boolean('logs')->default(true);
            $table->string('log_ip_masking', 10)->default('off');
            $table->boolean('security_watch')->default(true);
            $table->boolean('status_page_new')->default(false);
            $table->string('updates', 12)->default('automatic');
            $table->timestamps();
            $table->index(['workspace_id', 'is_default']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('machine_policy_id')->nullable()->constrained('machine_policies')->nullOnDelete();
            $table->boolean('policy_pending')->default(false);
        });

        Schema::table('machine_groups', function (Blueprint $table) {
            $table->foreignId('machine_policy_id')->nullable()->constrained('machine_policies')->nullOnDelete();
        });

        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->foreignId('machine_policy_id')->nullable()->constrained('machine_policies')->nullOnDelete();
            $table->foreignId('machine_group_id')->nullable()->constrained('machine_groups')->nullOnDelete();
        });

        $masked = DB::table('machines')->whereIn('log_ip_masking', ['partial', 'hash'])
            ->select(['workspace_id', 'log_ip_masking'])->distinct()->get();
        foreach ($masked as $row) {
            $id = DB::table('machine_policies')->insertGetId([
                'workspace_id' => $row->workspace_id,
                'name' => $row->log_ip_masking === 'hash' ? 'Masked IPs (coded)' : 'Masked IPs',
                'is_default' => false,
                'log_ip_masking' => $row->log_ip_masking,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('machines')->where('workspace_id', $row->workspace_id)->where('log_ip_masking', $row->log_ip_masking)
                ->update(['machine_policy_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_group_id');
            $table->dropConstrainedForeignId('machine_policy_id');
        });
        Schema::table('machine_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_policy_id');
        });
        Schema::table('machines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_policy_id');
            $table->dropColumn('policy_pending');
        });
        Schema::dropIfExists('machine_policies');
    }
};
