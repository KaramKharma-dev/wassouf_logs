<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('wish_rows_raw', function (Blueprint $table) {
            $table->string('service', 100)->nullable()->after('reference');
            $table->index('service');
        });
    }

    public function down(): void {
        Schema::table('wish_rows_raw', function (Blueprint $table) {
            $table->dropIndex(['service']);
            $table->dropColumn('service');
        });
    }
};
