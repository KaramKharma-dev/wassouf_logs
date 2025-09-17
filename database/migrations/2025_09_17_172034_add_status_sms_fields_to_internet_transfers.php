<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('internet_transfers', function (Blueprint $t) {
            $t->string('status')->default('PENDING')->index(); // PENDING|COMPLETED|FAILED
            $t->timestamp('confirmed_at')->nullable();
            $t->string('sms_hash', 191)->nullable()->unique();      // بصمة SMS لمنع التكرار
            $t->json('sms_meta')->nullable();                      // تخزين نص/مرسل SMS
            $t->string('idempotency_key', 191)->nullable()->unique(); // لمنشئ العملية من التطبيق
        });
    }

    public function down(): void {
        Schema::table('internet_transfers', function (Blueprint $t) {
            $t->dropColumn(['status','confirmed_at','sms_hash','sms_meta','idempotency_key']);
        });
    }
};
