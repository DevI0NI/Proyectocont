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
        Schema::create('rental_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('address', 200)->nullable();
            $table->string('currency', 3)->default('GTQ');
            $table->timestamps();
        });
        Schema::create('rental_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('rental_locations');
            $table->string('code', 20);
            $table->string('status', 20)->default('available');
            $table->timestamps();
            $table->unique(['location_id', 'code']);
        });
        Schema::create('rental_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('rental_locations');
            $table->unsignedInteger('hourly_cents');
            $table->unsignedInteger('daily_cents');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');
        });
        Schema::create('rental_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('rental_locations');
            $table->foreignId('space_id')->constrained('rental_spaces');
            $table->foreignId('rate_id')->constrained('rental_rates');
            $table->string('plate', 20);
            $table->string('active_plate', 20)->nullable()->unique();
            $table->unsignedBigInteger('active_space_id')->nullable()->unique();
            $table->string('driver', 120);
            $table->string('customer', 120)->nullable();
            $table->string('billing_unit', 10);
            $table->unsignedInteger('rate_cents');
            $table->string('currency', 3);
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
            $table->unsignedInteger('billable_units')->nullable();
            $table->unsignedBigInteger('total_cents')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->index(['location_id', 'exited_at']);
        });
        Schema::create('rental_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('rental_locations');
            $table->foreignId('stay_id')->nullable()->constrained('rental_stays');
            $table->string('action', 40);
            $table->unsignedBigInteger('user_id');
            $table->json('details');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_events');
        Schema::dropIfExists('rental_stays');
        Schema::dropIfExists('rental_rates');
        Schema::dropIfExists('rental_spaces');
        Schema::dropIfExists('rental_locations');
    }
};
