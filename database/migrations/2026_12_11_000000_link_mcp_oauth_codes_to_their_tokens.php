<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_oauth_codes', function (Blueprint $table) {
            $table->boolean('redirect_uri_sent')->default(false);
            $table->foreignId('mcp_token_id')->nullable()->constrained('mcp_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_oauth_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mcp_token_id');
            $table->dropColumn('redirect_uri_sent');
        });
    }
};
