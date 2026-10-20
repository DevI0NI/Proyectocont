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
        Schema::create('maintenance_jobs', function (Blueprint $table): void {
            $table->id();
            $table->integer('container_id');
            $table->integer('branch_id');
            $table->integer('mechanic_id');
            $table->foreignId('ingress_cycle_id')->constrained('container_ingress_cycles')->restrictOnDelete();
            $table->string('status', 30)->default('in_progress');
            $table->text('diagnosis');
            $table->text('work_performed')->nullable();
            $table->boolean('visible_damage')->default(false);
            $table->text('evidence_exception')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->foreign('container_id')->references('id')->on('contenedores')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('sucursales')->restrictOnDelete();
            $table->foreign('mechanic_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['branch_id', 'status']);
            $table->index(['ingress_cycle_id', 'status']);
        });
        Schema::create('maintenance_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('maintenance_jobs')->restrictOnDelete();
            $table->foreignId('part_id')->constrained('repair_parts')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('unit', 30);
            $table->decimal('quantity', 12, 3);
            $table->unique(['job_id', 'part_id']);
        });
        Schema::create('maintenance_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('maintenance_jobs')->restrictOnDelete();
            $table->string('stage', 15);
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->integer('uploaded_by');
            $table->timestamp('created_at');
        });
        Schema::create('maintenance_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained('maintenance_jobs')->restrictOnDelete();
            $table->integer('branch_id');
            $table->integer('requested_by');
            $table->string('status', 30)->default('pending');
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
        Schema::create('maintenance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('maintenance_jobs')->restrictOnDelete();
            $table->integer('user_id');
            $table->string('action', 40);
            $table->json('before_data')->nullable();
            $table->json('after_data');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['maintenance_events', 'maintenance_review_requests', 'maintenance_evidence', 'maintenance_materials', 'maintenance_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
