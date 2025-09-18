<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // php artisan make:migration alter_usd_transfers_add_confirm_fields
    public function up(): void
    {
        Schema::table('usd_transfers', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->decimal('confirmed_amount_usd', 12, 2)->default(0)->after('amount_usd');
            $table->unsignedInteger('confirmed_messages')->default(0)->after('confirmed_amount_usd');
        });
    }
    public function down(): void
    {
        Schema::table('usd_transfers', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn(['confirmed_amount_usd','confirmed_messages']);
        });
    }

};
