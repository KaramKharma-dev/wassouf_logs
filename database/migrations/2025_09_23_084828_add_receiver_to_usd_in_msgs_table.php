<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('usd_in_msgs', function (Blueprint $table) {
      if (!Schema::hasColumn('usd_in_msgs','receiver_number')) {
        $table->string('receiver_number', 20)->nullable()->after('msisdn');
        $table->index(['receiver_number']);
      }
    });
  }
  public function down(): void {
    Schema::table('usd_in_msgs', function (Blueprint $table) {
      if (Schema::hasColumn('usd_in_msgs','receiver_number')) {
        $table->dropIndex(['receiver_number']);
        $table->dropColumn('receiver_number');
      }
    });
  }
};
