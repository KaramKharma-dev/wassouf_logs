<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('days_transfers', function (Blueprint $table) {
      $table->enum('status', ['OPEN','PENDING_RECON','RECONCILED','SHORTAGE','MISMATCH'])
            ->default('OPEN')->after('provider');
      $table->dateTime('window_started_at')->nullable()->after('status');
      $table->dateTime('window_ends_at')->nullable()->after('window_started_at');
      $table->decimal('sum_incoming_usd', 12, 2)->default(0)->after('amount_usd');
      $table->json('expected_vouchers')->nullable()->after('sum_incoming_usd');
      $table->string('expectation_rule', 50)->nullable()->after('expected_vouchers');
      $table->dateTime('reconciled_at')->nullable()->after('expectation_rule');
    });
  }
  public function down(): void {
    Schema::table('days_transfers', function (Blueprint $table) {
      $table->dropColumn([
        'status','window_started_at','window_ends_at',
        'sum_incoming_usd','expected_vouchers','expectation_rule','reconciled_at'
      ]);
    });
  }
};

