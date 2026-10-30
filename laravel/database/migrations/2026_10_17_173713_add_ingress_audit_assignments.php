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
        Schema::create('container_ingress_cycles', function (Blueprint $table): void {
            $table->id();
            $table->integer('container_id');
            $table->integer('branch_id');
            $table->foreignId('shipping_line_id')->constrained('shipping_lines')->restrictOnDelete();
            $table->string('shipping_line_name', 120);
            $table->foreignId('audit_company_id')->nullable()->constrained('external_audit_companies')->restrictOnDelete();
            $table->string('audit_company_name')->nullable();
            $table->string('audit_reference', 255)->nullable();
            $table->dateTime('entered_at');
            $table->dateTime('closed_at')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->foreign('container_id')->references('id')->on('contenedores')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('sucursales')->restrictOnDelete();
        });
        Schema::table('contenedores', function (Blueprint $table): void {
            $table->foreignId('current_ingress_cycle_id')->nullable()->constrained('container_ingress_cycles')->restrictOnDelete();
        });
        Schema::table('pyp_auditoria', function (Blueprint $table): void {
            $table->foreignId('ingress_cycle_id')->nullable()->constrained('container_ingress_cycles')->restrictOnDelete();
        });
        Schema::create('container_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->integer('container_id');
            $table->foreignId('ingress_cycle_id')->nullable()->constrained('container_ingress_cycles')->restrictOnDelete();
            $table->integer('user_id');
            $table->string('event_type', 40);
            $table->string('reason', 255);
            $table->json('before_data')->nullable();
            $table->json('after_data');
            $table->timestamp('created_at');
            $table->index(['container_id', 'created_at']);
            $table->foreign('container_id')->references('id')->on('contenedores')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_assignment_events');
        Schema::table('pyp_auditoria', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ingress_cycle_id');
        });
        Schema::table('contenedores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_ingress_cycle_id');
        });
        Schema::dropIfExists('container_ingress_cycles');
    }
};
