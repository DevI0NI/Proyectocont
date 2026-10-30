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
            $table->foreignId('external_audit_company_id')->nullable()->constrained('external_audit_companies')->restrictOnDelete();
            $table->string('external_access_level', 20)->nullable();
        });
        Schema::create('external_audit_user_branch', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->primary(['user_id', 'branch_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('sucursales')->restrictOnDelete();
        });
        Schema::table('pyp_auditoria', function (Blueprint $table): void {
            $table->foreignId('external_audit_company_id')->nullable()->constrained('external_audit_companies')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pyp_auditoria', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_audit_company_id');
        });
        Schema::dropIfExists('external_audit_user_branch');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_audit_company_id');
            $table->dropColumn('external_access_level');
        });
    }
};
