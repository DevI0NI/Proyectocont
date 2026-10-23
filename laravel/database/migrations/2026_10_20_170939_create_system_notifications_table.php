<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('branch_id');
            $table->string('category', 20);
            $table->string('audience', 20);
            $table->string('event_key', 120);
            $table->unsignedBigInteger('job_id')->nullable();
            $table->unsignedInteger('container_id')->nullable();
            $table->unsignedBigInteger('ingress_cycle_id')->nullable();
            $table->unsignedInteger('audit_company_id')->nullable();
            $table->string('title', 160);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'event_key']);
            $table->index(['user_id', 'branch_id', 'read_at', 'created_at'], 'notifications_inbox_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
