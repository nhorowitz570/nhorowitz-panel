<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_email_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'domain']);
            $table->index('domain');
        });
        if ($this->mysql()) {
            Schema::table('workspace_email_domains', function (Blueprint $table) {
                $table->string('verified_domain', 253)->nullable()->virtualAs('case when verified_at is not null then domain end');
                $table->unique('verified_domain', 'workspace_email_domains_verified_unique');
            });
        } else {
            DB::statement('create unique index workspace_email_domains_verified_unique on workspace_email_domains (domain) where verified_at is not null');
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('domain_join', 16)->default('off');
            $table->string('domain_join_role', 16)->default('member');
            $table->foreignId('domain_join_custom_role_id')->nullable()
                ->constrained('workspace_custom_roles')->nullOnDelete();
            $table->boolean('require_google_sign_in')->default(false);
        });

        Schema::create('workspace_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253)->nullable();
            $table->string('state', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'state']);
            $table->index(['user_id', 'state']);
        });
        if ($this->mysql()) {
            Schema::table('workspace_join_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('pending_user_id')->nullable()->virtualAs("case when state = 'pending' then user_id end");
                $table->unique(['workspace_id', 'pending_user_id'], 'workspace_join_requests_pending_unique');
            });
        } else {
            DB::statement("create unique index workspace_join_requests_pending_unique on workspace_join_requests (workspace_id, user_id) where state = 'pending'");
        }
    }

    private function mysql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_join_requests');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domain_join_custom_role_id');
            $table->dropColumn(['domain_join', 'domain_join_role', 'require_google_sign_in']);
        });

        Schema::dropIfExists('workspace_email_domains');
    }
};
