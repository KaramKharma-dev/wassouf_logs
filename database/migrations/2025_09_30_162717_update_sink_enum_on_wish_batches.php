<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `wish_batches`
            MODIFY `sink` ENUM('raw','alt','pc') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `wish_batches`
            MODIFY `sink` ENUM('raw','alt') NOT NULL
        ");
    }
};
