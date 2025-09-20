<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('wish_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('wish_batches', 'sink')) {
                $table->enum('sink', ['raw','alt'])->default('raw')->after('status');
                $table->index('sink');
            }
            if (!Schema::hasColumn('wish_batches', 'currency')) {
                $table->enum('currency', ['USD','LBP'])->default('USD')->after('sink');
                $table->index('currency');
            }
        });
    }
    public function down(): void {
        Schema::table('wish_batches', function (Blueprint $table) {
            if (Schema::hasColumn('wish_batches', 'currency')) {
                $table->dropIndex(['currency']);
                $table->dropColumn('currency');
            }
            if (Schema::hasColumn('wish_batches', 'sink')) {
                $table->dropIndex(['sink']);
                $table->dropColumn('sink');
            }
        });
    }
};
