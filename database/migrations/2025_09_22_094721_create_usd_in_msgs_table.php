<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('usd_in_msgs', function (Blueprint $table) {
      $table->bigIncrements('id');
      $table->string('msisdn', 20);                // رقم المرسل
      $table->enum('provider', ['alfa','mtc']);
      $table->decimal('amount', 12, 2);            // 3, 2.5, 2, 1.5, 1, 0.5
      $table->timestamp('received_at');
      $table->timestamps();
      $table->index(['msisdn','provider','received_at']);
    });
  }
  public function down(): void {
    Schema::dropIfExists('usd_in_msgs');
  }
};
