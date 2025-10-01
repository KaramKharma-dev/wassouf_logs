<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('amount');
        });
    }
    public function down(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
