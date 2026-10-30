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
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('requires_password_setup')->default(false);
            $table->unsignedInteger('credential_version')->default(1);
        });
        Schema::create('account_recovery_requests', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->index();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->string('fingerprint', 64);
            $table->integer('reviewed_by')->nullable();
            $table->timestamps();
        });
        Schema::create('account_credential_challenges', function (Blueprint $table): void {
            $table->integer('user_id')->primary();
            $table->uuid('delivery_key');
            $table->string('fingerprint', 64);
            $table->string('code_hash')->nullable();
            $table->string('grant_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('grant_expires_at')->nullable();
        });
        Schema::create('account_security_events', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->index();
            $table->integer('actor_id')->nullable();
            $table->string('action', 50);
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_security_events');
        Schema::dropIfExists('account_credential_challenges');
        Schema::dropIfExists('account_recovery_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['requires_password_setup', 'credential_version']);
        });
    }
};
