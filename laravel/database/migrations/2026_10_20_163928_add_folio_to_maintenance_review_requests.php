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
        Schema::create('maintenance_counters', function (Blueprint $table): void {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });
        Schema::table('maintenance_review_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('folio')->nullable()->unique();
        });
        DB::transaction(function (): void {
            $number = 0;
            foreach (DB::table('maintenance_review_requests')->orderBy('created_at')->orderBy('id')->get(['id']) as $request) {
                DB::table('maintenance_review_requests')->where('id', $request->id)->update(['folio' => ++$number]);
            }
            DB::table('maintenance_counters')->insert(['name' => 'review_request', 'last_value' => $number]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_review_requests', function (Blueprint $table) {
            $table->dropUnique(['folio']);
            $table->dropColumn('folio');
        });
        Schema::dropIfExists('maintenance_counters');
    }
};
