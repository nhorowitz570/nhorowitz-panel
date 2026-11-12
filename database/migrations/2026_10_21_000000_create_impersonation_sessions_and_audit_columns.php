<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('user_name');
            $table->string('user_email');
            $table->string('reason', 500);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_how', 20)->nullable();

            $table->index('ended_at');
            $table->index('user_id');
        });

        Schema::create('impersonation_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonation_session_id')->constrained()->cascadeOnDelete();
            $table->string('route', 120);
            $table->json('parameters')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('impersonation_session_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable()->after('impersonation_session_id');
            $table->string('actor_email')->nullable()->after('actor_name');
            $table->string('source', 20)->nullable()->after('meta');
            $table->string('ip', 45)->nullable()->after('source');
            $table->string('user_agent')->nullable()->after('ip');
            $table->string('request_id', 36)->nullable()->after('user_agent');
        });

        $last = (int) DB::table('activities')->max('id');
        for ($from = 1; $from <= $last; $from += 5000) {
            DB::table('activities')->whereNotNull('user_id')->whereBetween('id', [$from, $from + 4999])->update([
                'actor_name' => DB::raw('(select name from users where users.id = activities.user_id)'),
                'actor_email' => DB::raw('(select email from users where users.id = activities.user_id)'),
            ]);
        }

        Schema::table('admin_actions', function (Blueprint $table) {
            $table->foreignId('impersonation_session_id')->nullable()->after('admin_id')->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable()->after('meta');
            $table->string('user_agent')->nullable()->after('ip');
        });

        foreach (['daemon_jobs', 'workflow_runs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('impersonation_session_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['daemon_jobs', 'workflow_runs', 'admin_actions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('impersonation_session_id');
            });
        }
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->dropColumn(['ip', 'user_agent']);
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonation_session_id');
            $table->dropColumn(['actor_name', 'actor_email', 'source', 'ip', 'user_agent', 'request_id']);
        });
        Schema::dropIfExists('impersonation_views');
        Schema::dropIfExists('impersonation_sessions');
    }
};
