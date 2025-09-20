<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('internet_transfers', function (Blueprint $table) {
            // احذف القيد الفريد
            $table->dropUnique(['sms_hash']); // اسم الفهرس الافتراضي
            // ثم أضف فهرس عادي
            $table->index('sms_hash');
        });
    }

    public function down(): void
    {
        Schema::table('internet_transfers', function (Blueprint $table) {
            // أعد الفريد كما كان
            $table->dropIndex(['sms_hash']);
            $table->unique('sms_hash');
        });
    }
};
