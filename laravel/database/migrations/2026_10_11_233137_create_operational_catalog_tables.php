<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['shipping_lines', 'external_audit_companies', 'container_iso_codes', 'repair_parts', 'repair_services'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->unsignedTinyInteger('status')->default(1)->index();
                if (in_array($name, ['shipping_lines', 'external_audit_companies'], true)) {
                    $table->string('name_key', 120)->unique();
                    $table->string('contact_name', 120)->nullable();
                    $table->string('email', 150)->nullable();
                    $table->string('phone', 30)->nullable();
                } else {
                    $table->string('code', $name === 'container_iso_codes' ? 4 : 30)->unique();
                }
                if ($name === 'container_iso_codes') {
                    $table->unsignedInteger('length_mm');
                    $table->unsignedInteger('height_mm');
                    $table->unsignedInteger('width_mm');
                    $table->string('type_name', 80);
                    $table->string('source_url', 500);
                }
                if (in_array($name, ['repair_parts', 'repair_services'], true)) {
                    $table->string('unit', 30);
                    $table->decimal('reference_cost', 10, 2)->nullable();
                    $table->string('currency', 3)->default('GTQ');
                }
                $table->timestamps();
            });
        }
        Schema::create('shipping_line_branch', function (Blueprint $table): void {
            $table->foreignId('shipping_line_id')->constrained('shipping_lines')->restrictOnDelete();
            $table->integer('branch_id');
            $table->foreign('branch_id')->references('id')->on('sucursales')->restrictOnDelete();
            $table->primary(['shipping_line_id', 'branch_id']);
        });
        Schema::create('shipping_line_auditor', function (Blueprint $table): void {
            $table->foreignId('shipping_line_id')->constrained('shipping_lines')->restrictOnDelete();
            $table->foreignId('auditor_id')->constrained('external_audit_companies')->restrictOnDelete();
            $table->primary(['shipping_line_id', 'auditor_id']);
        });
        Schema::create('operational_catalog_events', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog', 30);
            $table->unsignedBigInteger('record_id');
            $table->integer('user_id')->nullable();
            $table->string('action', 30);
            $table->json('before_data')->nullable();
            $table->json('after_data');
            $table->timestamp('created_at');
            $table->index(['catalog', 'record_id']);
        });
        foreach ([
            ['code' => '22G1', 'name' => 'Uso general de 20 pies', 'length_mm' => 6058, 'height_mm' => 2591],
            ['code' => '42G1', 'name' => 'Uso general de 40 pies', 'length_mm' => 12192, 'height_mm' => 2591],
            ['code' => '45G1', 'name' => 'Uso general de 40 pies High Cube', 'length_mm' => 12192, 'height_mm' => 2896],
        ] as $record) {
            DB::table('container_iso_codes')->insert($record + [
                'width_mm' => 2438, 'type_name' => 'Uso general', 'source_url' => 'https://www.bic-code.org/size-type-code/',
                'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['operational_catalog_events', 'shipping_line_auditor', 'shipping_line_branch', 'repair_services', 'repair_parts', 'container_iso_codes', 'external_audit_companies', 'shipping_lines'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
