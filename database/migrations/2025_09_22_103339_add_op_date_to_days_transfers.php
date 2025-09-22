// database/migrations/2025_09_22_100001_add_op_date_to_days_transfers.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('days_transfers', function (Blueprint $table) {
      if (!Schema::hasColumn('days_transfers','op_date')) {
        $table->date('op_date')->nullable()->after('receiver_number');
        $table->index(['receiver_number','provider','op_date'],'idx_days_daily');
      }
      if (!Schema::hasColumn('days_transfers','expected_vouchers')) {
        $table->json('expected_vouchers')->nullable();
      }
      if (!Schema::hasColumn('days_transfers','status')) {
        $table->enum('status',['OPEN','PENDING_RECON','RECONCILED','SHORTAGE','MISMATCH'])
              ->default('OPEN');
      }
      if (!Schema::hasColumn('days_transfers','sum_incoming_usd')) {
        $table->decimal('sum_incoming_usd',12,2)->default(0);
      }
      if (!Schema::hasColumn('days_transfers','expectation_rule')) {
        $table->string('expectation_rule',50)->nullable();
      }
    });
  }
  public function down(): void {
    Schema::table('days_transfers', function (Blueprint $table) {
      if (Schema::hasColumn('days_transfers','expectation_rule')) $table->dropColumn('expectation_rule');
      if (Schema::hasColumn('days_transfers','sum_incoming_usd'))  $table->dropColumn('sum_incoming_usd');
      if (Schema::hasColumn('days_transfers','status'))            $table->dropColumn('status');
      if (Schema::hasColumn('days_transfers','expected_vouchers')) $table->dropColumn('expected_vouchers');
      if (Schema::hasColumn('days_transfers','op_date'))           $table->dropColumn('op_date');
    });
  }
};
