<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('usd_transfers', function (Blueprint $table) {
            $table->unsignedSmallInteger('exp_msg_3')->default(0)->after('confirmed_messages');
            $table->unsignedSmallInteger('exp_msg_2')->default(0)->after('exp_msg_3');
            $table->unsignedSmallInteger('exp_msg_1')->default(0)->after('exp_msg_2');
        });
    }

    public function down(): void
    {
        Schema::table('usd_transfers', function (Blueprint $table) {
            $table->dropColumn(['exp_msg_3','exp_msg_2','exp_msg_1']);
        });
    }
};
