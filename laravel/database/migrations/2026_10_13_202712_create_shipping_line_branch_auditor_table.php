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
        Schema::create('shipping_line_branch_auditor', function (Blueprint $table): void {
            $table->unsignedBigInteger('shipping_line_id');
            $table->integer('branch_id');
            $table->foreignId('auditor_id')->constrained('external_audit_companies')->restrictOnDelete();
            $table->primary(['shipping_line_id', 'branch_id', 'auditor_id'], 'shipping_branch_auditor_primary');
            $table->foreign(['shipping_line_id', 'branch_id'], 'shipping_branch_auditor_branch_fk')
                ->references(['shipping_line_id', 'branch_id'])->on('shipping_line_branch')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_line_branch_auditor');
    }
};
