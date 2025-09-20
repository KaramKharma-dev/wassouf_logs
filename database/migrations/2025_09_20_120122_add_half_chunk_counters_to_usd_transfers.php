<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('usd_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('exp_msg_2_5')->default(0)->after('exp_msg_3');
            $table->unsignedBigInteger('exp_msg_1_5')->default(0)->after('exp_msg_2');
            $table->unsignedBigInteger('exp_msg_0_5')->default(0)->after('exp_msg_1');
        });
    }

    public function down(): void
    {
        Schema::table('usd_transfers', function (Blueprint $table) {
            $table->dropColumn(['exp_msg_2_5','exp_msg_1_5','exp_msg_0_5']);
        });
    }
};
