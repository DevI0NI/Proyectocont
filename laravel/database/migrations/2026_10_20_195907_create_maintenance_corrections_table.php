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
        Schema::create('maintenance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('maintenance_jobs')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('requested_by');
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->text('work_performed')->nullable();
            $table->json('materials')->nullable();
            $table->json('photos')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['job_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_corrections');
    }
};
