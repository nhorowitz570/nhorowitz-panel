<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_subscribers', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable');
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('confirm_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['subscribable_type', 'subscribable_id', 'email'], in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'status_subscribers_subscriber_unique' : null);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_subscribers');
    }
};
